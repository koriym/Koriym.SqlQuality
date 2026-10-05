<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_key_last;
use function count;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function preg_match_all;

/**
 * Reduces an information_schema.OPTIMIZER_TRACE row to the parts that explain the final plan.
 *
 * The raw trace is 8-50 KB per statement and append-only: every join order the optimizer tried is kept,
 * marked chosen or pruned. Each query block (EXPLAIN select_id, trace select#) is planned on its own, so
 * the excerpt keeps, per block and per table in EXPLAIN order, the node whose plan_prefix is exactly the
 * block's tables before it (that is the path the optimizer ended on), plus the range analysis and any
 * index recheck for that table, and per statement the subquery transformations and condition rewrites.
 *
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type OptimizerTraceExcerpt from Types
 * @psalm-import-type OptimizerTraceTable from Types
 * @psalm-type TraceNode = array<array-key, mixed>
 * @psalm-type Tagged = array{select: int|null, node: TraceNode}
 * @psalm-type Collected = array{
 *   rows_estimation: list<Tagged>,
 *   plans: list<Tagged>,
 *   rechecks: list<Tagged>,
 *   transformations: list<TraceNode>,
 *   condition_processing: list<TraceNode>
 * }
 */
final class OptimizerTrace
{
    /**
     * @param string        $trace   the TRACE column; empty when INSUFFICIENT_PRIVILEGES, cut off when MISSING_BYTES_BEYOND_MAX_MEM_SIZE
     * @param ExplainResult $explain the EXPLAIN FORMAT=JSON the trace was recorded for
     *
     * @return OptimizerTraceExcerpt|null null when the trace is not a complete JSON document
     */
    public static function excerpt(string $trace, array $explain): array|null
    {
        $decoded = json_decode($trace, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var Collected $collected */
        $collected = ['rows_estimation' => [], 'plans' => [], 'rechecks' => [], 'transformations' => [], 'condition_processing' => []];
        self::collect($decoded, $collected, null);

        $excerptTables = [];
        /** @var array<int, list<string>> $prefixes trace identifiers of the tables already placed, per query block */
        $prefixes = [];
        foreach ((new ExplainWalker())->tableAccesses($explain['query_block']) as $access) {
            $select = self::selectId($explain['query_block'], $access['path']);
            $tableName = $access['table']['table_name'];
            $identifier = self::identifier($tableName, $select, $collected);
            if ($identifier === null) {
                continue;
            }

            $prefix = $prefixes[$select] ?? [];
            $entry = ['table' => $tableName];

            $estimation = self::find($collected['rows_estimation'], $identifier, $select, static fn (array $node): bool => isset($node['range_analysis']) && is_array($node['range_analysis']));
            if ($estimation !== null) {
                /** @var array<string, mixed> $rangeAnalysis */
                $rangeAnalysis = $estimation['range_analysis'];
                $entry['range_analysis'] = $rangeAnalysis;
            }

            $plan = self::find($collected['plans'], $identifier, $select, static fn (array $node): bool => $node['plan_prefix'] === $prefix);
            if ($plan !== null) {
                /** @var list<array<string, mixed>> $paths */
                $paths = $plan['best_access_path']['considered_access_paths'] ?? [];
                $entry['considered_access_paths'] = $paths;
            }

            $recheck = self::find($collected['rechecks'], $identifier, $select, static fn (array $node): bool => true);
            if ($recheck !== null) {
                /** @var array<string, mixed> $rechecking */
                $rechecking = $recheck['rechecking_index_usage'];
                $entry['rechecking_index_usage'] = $rechecking;
            }

            $excerptTables[] = $entry;
            $prefixes[$select] = [...$prefix, $identifier];
        }

        return [
            'tables' => $excerptTables,
            'transformations' => $collected['transformations'],
            'condition_processing' => $collected['condition_processing'],
        ];
    }

    /**
     * @param TraceNode $node
     * @param Collected $collected
     * @param int|null  $select    the select# of the enclosing join_optimization / join_preparation
     */
    private static function collect(array $node, array &$collected, int|null $select): void
    {
        if (is_int($node['select#'] ?? null)) {
            $select = $node['select#'];
        }

        if (isset($node['rows_estimation']) && is_array($node['rows_estimation'])) {
            foreach ($node['rows_estimation'] as $estimation) {
                if (is_array($estimation) && is_string($estimation['table'] ?? null)) {
                    $collected['rows_estimation'][] = ['select' => $select, 'node' => $estimation];
                }
            }
        }

        if (isset($node['plan_prefix'], $node['best_access_path']) && is_array($node['plan_prefix']) && is_string($node['table'] ?? null)) {
            $collected['plans'][] = ['select' => $select, 'node' => $node];
        }

        if (isset($node['rechecking_index_usage']) && is_array($node['rechecking_index_usage']) && is_string($node['table'] ?? null)) {
            $collected['rechecks'][] = ['select' => $select, 'node' => $node];
        }

        if (isset($node['transformation']) && is_array($node['transformation']) && isset($node['transformation']['from'])) {
            $collected['transformations'][] = $node['transformation'];
        }

        if (isset($node['condition_processing']) && is_array($node['condition_processing'])) {
            $collected['condition_processing'][] = $node['condition_processing'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::collect($child, $collected, $select);
            }
        }
    }

    /**
     * The select_id of the query_block enclosing the table at $path.
     *
     * @param TraceNode       $queryBlock
     * @param list<array-key> $path
     */
    private static function selectId(array $queryBlock, array $path): int
    {
        $select = is_int($queryBlock['select_id'] ?? null) ? $queryBlock['select_id'] : 0;
        $node = $queryBlock;
        foreach ($path as $key) {
            $child = $node[$key] ?? null;
            if (! is_array($child)) {
                break;
            }

            $node = $child;
            if (is_int($node['select_id'] ?? null)) {
                $select = $node['select_id'];
            }
        }

        return $select;
    }

    /**
     * The trace names a table `t` or `t` `alias`; EXPLAIN names it by alias when there is one.
     * A table of the same query block is preferred so a name reused across blocks is not mixed up.
     *
     * @param Collected $collected
     */
    private static function identifier(string $tableName, int $select, array $collected): string|null
    {
        $fallback = null;
        foreach ([...$collected['rows_estimation'], ...$collected['plans']] as $tagged) {
            /** @var string $candidate */
            $candidate = $tagged['node']['table'];
            if (self::explainName($candidate) !== $tableName) {
                continue;
            }

            if ($tagged['select'] === $select) {
                return $candidate;
            }

            $fallback ??= $candidate;
        }

        return $fallback;
    }

    /**
     * @param list<Tagged>              $tagged
     * @param callable(TraceNode): bool $accept
     *
     * @return TraceNode|null the first node for $identifier in the same query block (or in no block at all) that $accept takes
     */
    private static function find(array $tagged, string $identifier, int $select, callable $accept): array|null
    {
        foreach ($tagged as $item) {
            if (($item['select'] === $select || $item['select'] === null) && $item['node']['table'] === $identifier && $accept($item['node'])) {
                return $item['node'];
            }
        }

        return null;
    }

    private static function explainName(string $identifier): string
    {
        if (preg_match_all('/`([^`]*)`/', $identifier, $matches) === 0 || count($matches[1]) === 0) {
            return $identifier;
        }

        return $matches[1][array_key_last($matches[1])];
    }
}

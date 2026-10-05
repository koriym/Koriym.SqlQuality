<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_key_last;
use function count;
use function is_array;
use function is_string;
use function json_decode;
use function preg_match_all;

/**
 * Reduces an information_schema.OPTIMIZER_TRACE row to the parts that explain the final plan.
 *
 * The raw trace is 8-50 KB per statement and append-only: every join order the optimizer tried is kept,
 * marked chosen or pruned. The excerpt keeps, for each table in EXPLAIN order, the node whose plan_prefix
 * is exactly the tables before it (that is the path the optimizer ended on), plus the range analysis and
 * any index recheck for that table, and per statement the subquery transformations and condition rewrites.
 *
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type OptimizerTraceExcerpt from Types
 * @psalm-import-type OptimizerTraceTable from Types
 * @psalm-type TraceNode = array<array-key, mixed>
 * @psalm-type Collected = array{
 *   rows_estimation: list<TraceNode>,
 *   plans: list<TraceNode>,
 *   rechecks: list<TraceNode>,
 *   transformations: list<TraceNode>,
 *   condition_processing: list<TraceNode>
 * }
 */
final class OptimizerTrace
{
    /**
     * @param string             $trace  the TRACE column; empty when INSUFFICIENT_PRIVILEGES, cut off when MISSING_BYTES_BEYOND_MAX_MEM_SIZE
     * @param list<ExplainTable> $tables the EXPLAIN tables in plan order
     *
     * @return OptimizerTraceExcerpt|null null when the trace is not a complete JSON document
     */
    public static function excerpt(string $trace, array $tables): array|null
    {
        $decoded = json_decode($trace, true);
        if (! is_array($decoded)) {
            return null;
        }

        /** @var Collected $collected */
        $collected = ['rows_estimation' => [], 'plans' => [], 'rechecks' => [], 'transformations' => [], 'condition_processing' => []];
        self::collect($decoded, $collected);

        $excerptTables = [];
        $prefix = [];
        foreach ($tables as $table) {
            $identifier = self::identifier($table['table_name'], $collected['rows_estimation'], $collected['plans']);
            if ($identifier === null) {
                continue;
            }

            $entry = ['table' => $table['table_name']];
            foreach ($collected['rows_estimation'] as $estimation) {
                if ($estimation['table'] === $identifier && isset($estimation['range_analysis']) && is_array($estimation['range_analysis'])) {
                    /** @var array<string, mixed> $rangeAnalysis */
                    $rangeAnalysis = $estimation['range_analysis'];
                    $entry['range_analysis'] = $rangeAnalysis;
                    break;
                }
            }

            foreach ($collected['plans'] as $plan) {
                if ($plan['table'] === $identifier && $plan['plan_prefix'] === $prefix) {
                    /** @var list<array<string, mixed>> $paths */
                    $paths = $plan['best_access_path']['considered_access_paths'] ?? [];
                    $entry['considered_access_paths'] = $paths;
                    break;
                }
            }

            foreach ($collected['rechecks'] as $recheck) {
                if ($recheck['table'] === $identifier) {
                    /** @var array<string, mixed> $rechecking */
                    $rechecking = $recheck['rechecking_index_usage'];
                    $entry['rechecking_index_usage'] = $rechecking;
                    break;
                }
            }

            $excerptTables[] = $entry;
            $prefix[] = $identifier;
        }

        return [
            'tables' => $excerptTables,
            'transformations' => $collected['transformations'],
            'condition_processing' => $collected['condition_processing'],
        ];
    }

    /**
     * @param array<array-key, mixed> $node
     * @param Collected               $collected
     */
    private static function collect(array $node, array &$collected): void
    {
        if (isset($node['rows_estimation']) && is_array($node['rows_estimation'])) {
            foreach ($node['rows_estimation'] as $estimation) {
                if (is_array($estimation) && is_string($estimation['table'] ?? null)) {
                    $collected['rows_estimation'][] = $estimation;
                }
            }
        }

        if (isset($node['plan_prefix'], $node['best_access_path']) && is_array($node['plan_prefix']) && is_string($node['table'] ?? null)) {
            $collected['plans'][] = $node;
        }

        if (isset($node['rechecking_index_usage']) && is_array($node['rechecking_index_usage']) && is_string($node['table'] ?? null)) {
            $collected['rechecks'][] = $node;
        }

        if (isset($node['transformation']) && is_array($node['transformation']) && isset($node['transformation']['from'])) {
            $collected['transformations'][] = $node['transformation'];
        }

        if (isset($node['condition_processing']) && is_array($node['condition_processing'])) {
            $collected['condition_processing'][] = $node['condition_processing'];
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                self::collect($child, $collected);
            }
        }
    }

    /**
     * The trace names a table `t` or `t` `alias`; EXPLAIN names it by alias when there is one.
     *
     * @param list<TraceNode> $estimations
     * @param list<TraceNode> $plans
     */
    private static function identifier(string $tableName, array $estimations, array $plans): string|null
    {
        foreach ([...$estimations, ...$plans] as $node) {
            /** @var string $candidate */
            $candidate = $node['table'];
            if (self::explainName($candidate) === $tableName) {
                return $candidate;
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

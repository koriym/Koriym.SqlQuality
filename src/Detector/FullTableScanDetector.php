<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_column;
use function array_flip;
use function array_intersect_key;
use function implode;
use function is_array;
use function sprintf;
use function str_starts_with;

/**
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type OptimizerTraceTable from Types
 * @psalm-import-type Suggestion from Types
 */
final class FullTableScanDetector implements DetectorInterface
{
    /** Below this row count a full scan is cheap enough to report as Info rather than the default Critical */
    private const LOW_VOLUME_ROWS = 100;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        $walker = new ExplainWalker();
        foreach ($context->tableAccesses() as $access) {
            $table = $access['table'];
            if ($table['access_type'] !== 'ALL' || str_starts_with($table['table_name'], '<') || isset($table['materialized_from_subquery']) || isset($table['table_function'])) {
                continue;
            }

            $evidence = array_intersect_key($table, array_flip(['table_name', 'rows_examined_per_scan', 'filtered', 'possible_keys', 'key', 'attached_condition']));
            $reason = null;
            if ($this->hasUnusedIndex($table)) {
                $select = $walker->selectId($context->explain['query_block'], $access['path']);
                $reason = $this->unusedIndexReason($context->optimizerTraceFor($table['table_name'], $select));
            }

            if ($reason !== null) {
                $evidence['optimizer_trace'] = $reason;
            }

            $findings[] = new Finding(
                evidence: $evidence,
                severity: $this->isLowVolume($table) ? 'Info' : null,
                suggestion: $this->suggest($context, $table, $reason),
            );
        }

        return $findings;
    }

    /** @param ExplainTable $table */
    private function isLowVolume(array $table): bool
    {
        return (float) ($table['rows_examined_per_scan'] ?? 0) < self::LOW_VOLUME_ROWS;
    }

    /** @param ExplainTable $table */
    private function hasUnusedIndex(array $table): bool
    {
        return ($table['possible_keys'] ?? []) !== [] && ($table['key'] ?? null) === null;
    }

    /**
     * @param ExplainTable              $table
     * @param array<string, mixed>|null $reason
     *
     * @return Suggestion|null
     */
    private function suggest(QueryContext $context, array $table, array|null $reason): array|null
    {
        $attachedCondition = $table['attached_condition'] ?? null;
        if ($attachedCondition !== null) {
            $groups = ConditionColumns::forAlias($attachedCondition, $table['table_name']);
            $columns = array_column($groups['equality'], 'column');
            if ($groups['range'] !== []) {
                $columns[] = $groups['range'][0]['column'];
            }

            $suggestion = IndexSuggestion::create($context, $table['table_name'], $columns);
            if ($suggestion !== null) {
                return $suggestion;
            }
        }

        if (! $this->hasUnusedIndex($table)) {
            return null;
        }

        if ($reason === null) {
            return ['kind' => 'review', 'description' => 'An index exists but is not used; check selectivity or statistics.'];
        }

        return match ($reason['cause']) {
            'cost' => [
                'kind' => 'review',
                'description' => sprintf(
                    'The range scan on %s was rejected on cost (%s of %s rows, cost %s against %s for the table scan); narrow the predicate or make the index covering.',
                    (string) $reason['index'],
                    (string) $reason['rows'],
                    (string) $reason['table_scan_rows'],
                    (string) $reason['cost'],
                    (string) $reason['table_scan_cost'],
                ),
            ],
            'index_merge_union' => [
                'kind' => 'rewrite',
                'description' => sprintf(
                    'No single index serves the OR; the index merge union of %s (cost %s) lost to the table scan (cost %s). Add a composite index or rewrite as UNION.',
                    implode(', ', (array) $reason['indexes']),
                    (string) $reason['cost'],
                    (string) $reason['table_scan_cost'],
                ),
            ],
            // Only join keys were usable on this table, so the scan is the plan rather than a missed index
            'join_key_only' => null,
            default => ['kind' => 'review', 'description' => 'An index exists but is not used; check selectivity or statistics.'],
        };
    }

    /**
     * Why the optimizer left possible_keys unused, read from the trace of this table.
     *
     * @param OptimizerTraceTable|null $trace
     *
     * @return array<string, mixed>|null
     */
    private function unusedIndexReason(array|null $trace): array|null
    {
        if ($trace === null) {
            return null;
        }

        $rangeAnalysis = $trace['range_analysis'] ?? null;
        if (is_array($rangeAnalysis) && is_array($rangeAnalysis['table_scan'] ?? null) && isset($rangeAnalysis['table_scan']['rows'], $rangeAnalysis['table_scan']['cost'])) {
            $tableScan = ['table_scan_rows' => $rangeAnalysis['table_scan']['rows'], 'table_scan_cost' => $rangeAnalysis['table_scan']['cost']];

            $alternatives = $rangeAnalysis['analyzing_range_alternatives']['range_scan_alternatives'] ?? [];
            foreach (is_array($alternatives) ? $alternatives : [] as $alternative) {
                if (is_array($alternative) && ($alternative['cause'] ?? null) === 'cost' && isset($alternative['index'], $alternative['rows'], $alternative['cost'])) {
                    return ['cause' => 'cost', 'index' => $alternative['index'], 'rows' => $alternative['rows'], 'cost' => $alternative['cost'], ...$tableScan];
                }
            }

            $unions = $rangeAnalysis['analyzing_index_merge_union'] ?? [];
            foreach (is_array($unions) ? $unions : [] as $union) {
                if (! is_array($union) || ! is_array($union['indexes_to_merge'] ?? null) || ! isset($union['total_cost'])) {
                    continue;
                }

                /** @var list<array<string, mixed>> $toMerge */
                $toMerge = $union['indexes_to_merge'];

                return ['cause' => 'index_merge_union', 'indexes' => array_column($toMerge, 'index_to_merge'), 'cost' => $union['total_cost'], ...$tableScan];
            }
        }

        $rejected = [];
        foreach ($trace['considered_access_paths'] ?? [] as $path) {
            if (($path['access_type'] ?? null) === 'ref' && ($path['usable'] ?? null) === false) {
                $rejected[] = $path['index'] ?? null;
                continue;
            }

            if (($path['access_type'] ?? null) !== 'scan') {
                return null;
            }
        }

        return $rejected === [] ? null : ['cause' => 'join_key_only', 'indexes' => $rejected];
    }
}

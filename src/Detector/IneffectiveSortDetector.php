<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_column;
use function array_slice;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function sprintf;
use function trim;

/**
 * @psalm-import-type ExplainNode from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type Suggestion from Types
 */
final class IneffectiveSortDetector implements DetectorInterface
{
    /** A filesort over an index lookup is reported from this many rows */
    private const ROW_THRESHOLD = 1000;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($this->orderingOperations($context->explain['query_block'], []) as ['path' => $path, 'node' => $ordering]) {
            if (($ordering['using_filesort'] ?? false) !== true) {
                continue;
            }

            $table = $this->sortedTable($context, $path);
            if ($table === null || isset($table['table_function']) || ! $this->isLargeScan($table)) {
                continue;
            }

            $key = $table['key'] ?? null;
            $findings[] = new Finding(
                evidence: [
                    'table_name' => $table['table_name'],
                    'access_type' => $table['access_type'],
                    'rows_examined_per_scan' => $table['rows_examined_per_scan'] ?? null,
                    'key' => $key,
                    'used_key_parts' => $table['used_key_parts'] ?? [],
                    'index_columns' => $key === null ? [] : $context->indexColumns($table['table_name'])[$key] ?? [],
                    'using_filesort' => true,
                    'using_temporary_table' => $ordering['using_temporary_table'] ?? false,
                ],
                suggestion: $this->suggest($context, $table),
            );
        }

        return $findings;
    }

    /**
     * @param ExplainNode     $node
     * @param list<array-key> $path
     *
     * @return list<array{path: list<array-key>, node: ExplainNode}>
     */
    private function orderingOperations(array $node, array $path): array
    {
        $found = [];
        foreach ($node as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $childPath = [...$path, $key];
            if ($key === 'ordering_operation') {
                $found[] = ['path' => $childPath, 'node' => $child];
            }

            $found = [...$found, ...$this->orderingOperations($child, $childPath)];
        }

        return $found;
    }

    /**
     * @param list<array-key> $orderingPath
     *
     * @return ExplainTable|null the table directly under the ordering_operation, else the first one beneath it
     */
    private function sortedTable(QueryContext $context, array $orderingPath): array|null
    {
        $first = null;
        foreach ($context->tableAccesses() as ['path' => $path, 'table' => $table]) {
            if (array_slice($path, 0, count($orderingPath)) !== $orderingPath) {
                continue;
            }

            if ($path === [...$orderingPath, 'table']) {
                return $table;
            }

            $first ??= $table;
        }

        return $first;
    }

    /** @param ExplainTable $table */
    private function isLargeScan(array $table): bool
    {
        return $table['access_type'] === 'ALL' || (int) ($table['rows_examined_per_scan'] ?? 0) >= self::ROW_THRESHOLD;
    }

    /**
     * @param ExplainTable $table
     *
     * @return Suggestion
     */
    private function suggest(QueryContext $context, array $table): array
    {
        $equality = array_column(ConditionColumns::forAlias($table['attached_condition'] ?? '', $table['table_name'])['equality'], 'column');
        $columns = array_values(array_unique([...$equality, ...$this->orderByColumns($context, $table['table_name'])]));
        if ($columns === []) {
            return ['kind' => 'review', 'description' => 'Consider an index on the WHERE equality columns followed by the ORDER BY columns so rows are read in sorted order.'];
        }

        $tableName = $context->aliases()[$table['table_name']] ?? $table['table_name'];
        $existing = $this->indexStartingWith($context, $table['table_name'], $columns);
        if ($existing !== null) {
            return ['kind' => 'review', 'description' => sprintf('%s already has %s starting with (%s) but the optimizer did not use it to order; review the filter selectivity and the LIMIT.', $tableName, $existing, implode(', ', $columns))];
        }

        return ['kind' => 'review', 'description' => sprintf('Consider an index on %s (%s), WHERE equality columns first and ORDER BY columns last, so rows are read in sorted order.', $tableName, implode(', ', $columns))];
    }

    /** @param list<string> $columns */
    private function indexStartingWith(QueryContext $context, string $aliasOrTable, array $columns): string|null
    {
        foreach ($context->indexColumns($aliasOrTable) as $name => $indexColumns) {
            if (array_slice($indexColumns, 0, count($columns)) === $columns) {
                return $name;
            }
        }

        return null;
    }

    /** @return list<string> ORDER BY columns that exist on the table; [] unless the statement has exactly one ORDER BY */
    private function orderByColumns(QueryContext $context, string $aliasOrTable): array
    {
        if (preg_match_all('/\bORDER\s+BY\s+(?<list>.+?)(?=\s+LIMIT\b|\s*[;)]|\s*$)/is', $context->sqlWithoutComments(), $matches) !== 1) {
            return [];
        }

        $columns = [];
        foreach (preg_split('/\s*,\s*/', trim($matches['list'][0])) ?: [] as $item) {
            if (preg_match('/^(?:`?\w+`?\.)*`?(?<column>\w+)`?(?:\s+(?:ASC|DESC))?$/i', trim($item), $match) !== 1) {
                continue;
            }

            if ($context->columnType($aliasOrTable, $match['column']) !== null) {
                $columns[] = $match['column'];
            }
        }

        return $columns;
    }
}

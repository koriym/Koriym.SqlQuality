<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_slice;
use function count;
use function is_array;

/**
 * @psalm-import-type ExplainNode from Types
 * @psalm-import-type Suggestion from Types
 */
final class TemporaryTableGroupingDetector implements DetectorInterface
{
    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($this->temporaryTableNodes($context->explain['query_block'], []) as ['path' => $path, 'key' => $key, 'node' => $node]) {
            $groupingItself = $key === 'grouping_operation';
            if (! $groupingItself && ! ($key === 'ordering_operation' && $this->groupsWithin($node))) {
                continue;
            }

            $findings[] = new Finding(
                evidence: ['path' => $path, 'node' => $key, 'tables' => $this->rowsExaminedBeneath($context, $path)],
                suggestion: $this->suggest($groupingItself),
            );
        }

        return $findings;
    }

    /**
     * @param ExplainNode     $node
     * @param list<array-key> $path
     *
     * @return list<array{path: list<array-key>, key: array-key, node: ExplainNode}>
     */
    private function temporaryTableNodes(array $node, array $path): array
    {
        $found = [];
        foreach ($node as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $childPath = [...$path, $key];
            if (($child['using_temporary_table'] ?? false) === true) {
                $found[] = ['path' => $childPath, 'key' => $key, 'node' => $child];
            }

            $found = [...$found, ...$this->temporaryTableNodes($child, $childPath)];
        }

        return $found;
    }

    /**
     * @param ExplainNode $node
     *
     * @return bool whether a grouping_operation sits beneath the node within the same query block
     */
    private function groupsWithin(array $node): bool
    {
        foreach ($node as $key => $child) {
            if ($key === 'grouping_operation') {
                return true;
            }

            if ($key !== 'query_block' && is_array($child) && $this->groupsWithin($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array-key> $nodePath
     *
     * @return array<string, int|null> table name => rows_examined_per_scan
     */
    private function rowsExaminedBeneath(QueryContext $context, array $nodePath): array
    {
        $rows = [];
        foreach ($context->tableAccesses() as ['path' => $path, 'table' => $table]) {
            if (array_slice($path, 0, count($nodePath)) === $nodePath) {
                $rows[$table['table_name']] = isset($table['rows_examined_per_scan']) ? (int) $table['rows_examined_per_scan'] : null;
            }
        }

        return $rows;
    }

    /** @return Suggestion */
    private function suggest(bool $groupingItself): array
    {
        if ($groupingItself) {
            return ['kind' => 'review', 'description' => 'Rows are grouped through a temporary table; review whether an index leading with the GROUP BY columns lets MySQL group in index order.'];
        }

        return ['kind' => 'review', 'description' => 'The grouped result is sorted through a temporary table; review whether ORDER BY can follow the GROUP BY columns or the sort can run on fewer rows.'];
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;
use Override;

use function in_array;
use function is_array;

/**
 * Detects index usage on low cardinality columns
 *
 * Low cardinality indexes (e.g., status, gender) often scan a large percentage
 * of table rows, making them inefficient compared to full table scans.
 *
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainTable from Types
 */
final class LowCardinalityIndexDetector implements DetectorInterface
{
    /** @param ExplainResult $explainResult */
    #[Override]
    public function detect(array $explainResult): bool
    {
        if (! isset($explainResult['query_block'])) {
            return false;
        }

        $queryBlock = $explainResult['query_block'];

        // Check single table access
        if (isset($queryBlock['table']) && is_array($queryBlock['table']) && $this->checkTable($queryBlock['table'])) {
            return true;
        }

        // Check nested loop joins
        if (isset($queryBlock['nested_loop']) && is_array($queryBlock['nested_loop'])) {
            foreach ($queryBlock['nested_loop'] as $nestedTable) {
                if (! is_array($nestedTable) || ! isset($nestedTable['table']) || ! is_array($nestedTable['table'])) {
                    continue;
                }

                /** @var ExplainTable $table */
                $table = $nestedTable['table'];
                if ($this->checkTable($table)) {
                    return true;
                }
            }
        }

        // Check ordering_operation
        if (
            isset($queryBlock['ordering_operation']['table']) &&
            is_array($queryBlock['ordering_operation']['table']) &&
            $this->checkTable($queryBlock['ordering_operation']['table'])
        ) {
            return true;
        }

        // Check grouping_operation
        if (
            isset($queryBlock['grouping_operation']['table']) &&
            is_array($queryBlock['grouping_operation']['table']) &&
            $this->checkTable($queryBlock['grouping_operation']['table'])
        ) {
            return true;
        }

        return false;
    }

    /** @param ExplainTable $table */
    private function checkTable(array $table): bool
    {
        // Must be using an index (not full scan)
        if (! isset($table['access_type']) || $table['access_type'] === 'ALL') {
            return false;
        }

        // Must be ref or range (index access)
        if (! in_array($table['access_type'], ['ref', 'range'], true)) {
            return false;
        }

        $rowsExamined = $table['rows_examined_per_scan'] ?? $table['rows'] ?? 0;
        $filtered = $table['filtered'] ?? 100.0;

        // If examining many rows with high filtered percentage, likely low cardinality
        // This means the index isn't selective enough
        if ($rowsExamined > 100 && $filtered > 80.0) {
            return true;
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;

/**
 * Detects index usage on low cardinality columns
 *
 * Low cardinality indexes (e.g., status, gender) often scan a large percentage
 * of table rows, making them inefficient compared to full table scans.
 *
 * @psalm-import-type ExplainResult from Types
 */
final class LowCardinalityIndexDetector implements DetectorInterface
{
    private const HIGH_SCAN_THRESHOLD = 0.5; // 50% of rows

    /**
     * @param ExplainResult $explainResult
     */
    public function detect(array $explainResult): bool
    {
        if (! isset($explainResult['query_block'])) {
            return false;
        }

        $queryBlock = $explainResult['query_block'];

        // Check single table access
        if (isset($queryBlock['table']) && $this->checkTable($queryBlock['table'])) {
            return true;
        }

        // Check nested loop joins
        if (isset($queryBlock['nested_loop']) && is_array($queryBlock['nested_loop'])) {
            foreach ($queryBlock['nested_loop'] as $nestedTable) {
                if (isset($nestedTable['table']) && $this->checkTable($nestedTable['table'])) {
                    return true;
                }
            }
        }

        // Check ordering_operation
        if (isset($queryBlock['ordering_operation']['table']) && $this->checkTable($queryBlock['ordering_operation']['table'])) {
            return true;
        }

        // Check grouping_operation
        if (isset($queryBlock['grouping_operation']['table']) && $this->checkTable($queryBlock['grouping_operation']['table'])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $table
     */
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

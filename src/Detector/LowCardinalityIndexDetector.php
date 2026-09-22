<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_flip;
use function array_intersect_key;
use function in_array;

/**
 * Detects index usage on low cardinality columns
 *
 * Low cardinality indexes (e.g., status, gender) often scan a large percentage
 * of table rows, making them inefficient compared to full table scans.
 *
 * @psalm-import-type ExplainTable from Types
 */
final class LowCardinalityIndexDetector implements DetectorInterface
{
    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($this->checkTable($table)) {
                $findings[] = new Finding(array_intersect_key($table, array_flip(['table_name', 'access_type', 'key', 'rows_examined_per_scan', 'filtered'])));
            }
        }

        return $findings;
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

        $rowsExamined = $table['rows_examined_per_scan'] ?? 0;
        $filtered = (float) ($table['filtered'] ?? 100.0);

        // If examining many rows with high filtered percentage, likely low cardinality
        // This means the index isn't selective enough
        if ($rowsExamined > 100 && $filtered > 80.0) {
            return true;
        }

        return false;
    }
}

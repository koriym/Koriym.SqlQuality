<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;

use function in_array;
use function is_array;

/**
 * Detects unnecessary DISTINCT operations
 *
 * @psalm-import-type ExplainResult from Types
 */
final class UnnecessaryDistinctDetector implements DetectorInterface
{
    /** @param ExplainResult $explainResult */
    public function detect(array $explainResult): bool
    {
        // Check for duplicates_removal in ordering_operation
        if (isset($explainResult['query_block']['ordering_operation']['duplicates_removal'])) {
            return $this->hasPrimaryKeyInUsedColumns($explainResult['query_block']['ordering_operation']['duplicates_removal']);
        }

        // Check for duplicates_removal at query_block level
        if (isset($explainResult['query_block']['duplicates_removal'])) {
            return $this->hasPrimaryKeyInUsedColumns($explainResult['query_block']['duplicates_removal']);
        }

        return false;
    }

    /**
     * Check if used_columns likely contains a primary key (column named 'id')
     *
     * @param array<string, mixed> $duplicatesRemoval
     */
    private function hasPrimaryKeyInUsedColumns(array $duplicatesRemoval): bool
    {
        if (! isset($duplicatesRemoval['table']['used_columns'])) {
            return false;
        }

        $usedColumns = $duplicatesRemoval['table']['used_columns'];
        if (! is_array($usedColumns)) {
            return false;
        }

        // If 'id' column is in used_columns, it's likely a primary key making DISTINCT unnecessary
        // This is a heuristic - ideally we'd check schema info
        return in_array('id', $usedColumns, true);
    }
}

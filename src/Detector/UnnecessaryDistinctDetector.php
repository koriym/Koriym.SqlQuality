<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function in_array;
use function is_array;

/**
 * Detects unnecessary DISTINCT operations
 *
 * @psalm-import-type DuplicatesRemovalOperation from Types
 */
final class UnnecessaryDistinctDetector implements DetectorInterface
{
    #[Override]
    public function detect(QueryContext $context): array
    {
        $queryBlock = $context->explain['query_block'];
        // Check for duplicates_removal in ordering_operation
        if (isset($queryBlock['ordering_operation']['duplicates_removal'])) {
            return $this->primaryKeyFindings($queryBlock['ordering_operation']['duplicates_removal']);
        }

        // Check for duplicates_removal at query_block level
        if (isset($queryBlock['duplicates_removal'])) {
            return $this->primaryKeyFindings($queryBlock['duplicates_removal']);
        }

        return [];
    }

    /**
     * Check if used_columns likely contains a primary key (column named 'id')
     *
     * @param DuplicatesRemovalOperation $duplicatesRemoval
     *
     * @return list<Finding>
     */
    private function primaryKeyFindings(array $duplicatesRemoval): array
    {
        if (! isset($duplicatesRemoval['table']['used_columns'])) {
            return [];
        }

        $usedColumns = $duplicatesRemoval['table']['used_columns'];
        if (! is_array($usedColumns)) {
            return [];
        }

        // If 'id' column is in used_columns, it's likely a primary key making DISTINCT unnecessary
        // This is a heuristic - ideally we'd check schema info
        if (! in_array('id', $usedColumns, true)) {
            return [];
        }

        return [new Finding(['table_name' => $duplicatesRemoval['table']['table_name'], 'used_columns' => $usedColumns])];
    }
}

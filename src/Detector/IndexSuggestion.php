<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;

use function array_slice;
use function count;
use function implode;

/**
 * Builds a composite index DDL suggestion, or null when a matching index already covers the columns.
 *
 * @psalm-import-type Suggestion from Types
 */
final class IndexSuggestion
{
    /**
     * @param list<string> $columns equality columns first, at most one range column last
     *
     * @return Suggestion|null null when an index already starts with these columns, in this order
     */
    public static function create(QueryContext $context, string $aliasOrTable, array $columns): array|null
    {
        if ($columns === [] || self::hasMatchingIndex($context, $aliasOrTable, $columns)) {
            return null;
        }

        $table = $context->aliases()[$aliasOrTable] ?? $aliasOrTable;
        $columnList = implode(', ', $columns);

        return [
            'kind' => 'index',
            'description' => 'Add a composite index covering ' . $columnList,
            'ddl' => 'CREATE INDEX idx_' . $table . '_' . implode('_', $columns) . ' ON ' . $table . ' (' . $columnList . ')',
        ];
    }

    /** @param list<string> $columns */
    private static function hasMatchingIndex(QueryContext $context, string $aliasOrTable, array $columns): bool
    {
        $count = count($columns);
        foreach ($context->indexColumns($aliasOrTable) as $indexColumns) {
            if (array_slice($indexColumns, 0, $count) === $columns) {
                return true;
            }
        }

        return false;
    }
}

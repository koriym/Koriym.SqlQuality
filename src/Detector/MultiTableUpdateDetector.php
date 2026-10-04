<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Koriym\SqlQuality\QueryContext;
use Override;

use function count;

/**
 * Detects multi-table UPDATE plans.
 *
 * MySQL EXPLAIN FORMAT=JSON for UPDATE ... JOIN may mark each updated table
 * with "update": true rather than exposing update_operation: multi_table.
 */
final class MultiTableUpdateDetector implements DetectorInterface
{
    /** @psalm-mutation-free */
    #[Override]
    public function detect(QueryContext $context): array
    {
        $walker = new ExplainWalker();
        if ($walker->contains($context->explain, 'update_operation', 'multi_table')) {
            return [new Finding(['update_operation' => 'multi_table'])];
        }

        $updatedTables = [];
        foreach ($context->tables() as $table) {
            if (($table['update'] ?? false) === true) {
                $updatedTables[] = $table['table_name'];
            }
        }

        if (count($updatedTables) < 2) {
            return [];
        }

        return [new Finding(['tables' => $updatedTables])];
    }
}

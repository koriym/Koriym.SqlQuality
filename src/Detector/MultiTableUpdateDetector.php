<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Override;

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
    public function detect(array $explainResult): bool
    {
        $walker = new ExplainWalker();
        if ($walker->contains($explainResult, 'update_operation', 'multi_table')) {
            return true;
        }

        $updatedTables = 0;
        foreach ($walker->tables($explainResult) as $table) {
            if (($table['update'] ?? false) === true) {
                $updatedTables++;
            }
        }

        return $updatedTables > 1;
    }
}

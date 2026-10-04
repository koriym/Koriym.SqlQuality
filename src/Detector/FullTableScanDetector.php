<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Override;

use function array_flip;
use function array_intersect_key;

final class FullTableScanDetector implements DetectorInterface
{
    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($table['access_type'] !== 'ALL') {
                continue;
            }

            $findings[] = new Finding(array_intersect_key($table, array_flip(['table_name', 'rows_examined_per_scan', 'possible_keys', 'key'])));
        }

        return $findings;
    }
}

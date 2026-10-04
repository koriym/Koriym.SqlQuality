<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Koriym\SqlQuality\QueryContext;
use Override;

final class TemporaryTableGroupingDetector implements DetectorInterface
{
    #[Override]
    public function detect(QueryContext $context): array
    {
        $path = (new ExplainWalker())->pathOf($context->explain['query_block'], 'using_temporary_table', true);
        if ($path === null) {
            return [];
        }

        return [new Finding(['path' => $path])];
    }
}

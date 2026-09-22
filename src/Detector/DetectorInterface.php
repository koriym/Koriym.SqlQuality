<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;

interface DetectorInterface
{
    /** @return list<Finding> */
    public function detect(QueryContext $context): array;
}

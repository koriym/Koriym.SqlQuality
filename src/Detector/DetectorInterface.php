<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;

/** @psalm-import-type ExplainResult from Types */
interface DetectorInterface
{
    public function detect(array $explainResult): bool;
}

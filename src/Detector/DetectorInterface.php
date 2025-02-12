<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;

/** @psalm-import-type ExplainResult from Types */
interface DetectorInterface
{
    /** @param ExplainResult $explainResult */
    public function detect(array $explainResult): bool;
}

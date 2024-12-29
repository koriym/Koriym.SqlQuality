<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

interface QueryLevelClassifierInterface
{
    /** @return string Level description ("Very High (> μ + 2σ)" etc) */
    public function classify(float $cost, float $mean, float $stdDev): string;
}

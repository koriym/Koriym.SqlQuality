<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Override;

class StatisticalQueryLevelClassifier implements QueryLevelClassifierInterface
{
    #[Override]
    public function classify(float $cost, float $mean, float $stdDev): string
    {
        if ($cost > $mean + 2.0 * $stdDev) {
            return '⚠️⚠️Very High (> μ + 2σ)';
        }

        if ($cost > $mean + $stdDev) {
            return '⚠️High (μ + σ to μ + 2σ)';
        }

        if ($cost > $mean - $stdDev) {
            return 'Medium (μ ± σ)';
        }

        return 'Low (< μ)';
    }
}

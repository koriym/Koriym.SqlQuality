<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Override;

use function array_column;
use function array_reduce;
use function array_sum;
use function count;
use function pow;
use function sqrt;

class QueryStatisticsCalculator implements QueryStatisticsInterface
{
    private array $queryResults = [];

    #[Override]
    public function calculate(array $queryResults): array
    {
        $this->queryResults = $queryResults;

        if (empty($queryResults)) {
            return [
                'total_count' => 0,
                'avg_cost' => 0,
                'std_dev' => 0,
            ];
        }

        $costs = array_column($queryResults, 'cost');
        $totalCount = count($costs);
        $mean = $totalCount > 0 ? (float) array_sum($costs) / (float) $totalCount : 0.0;

        // 標準偏差の計算
        $variance = $totalCount > 0 ? array_reduce(
            $costs,
            static fn (float $carry, float $cost) => $carry + pow($cost - $mean, 2),
            0.0,
        ) / (float) $totalCount : 0.0;

        return [
            'total_count' => $totalCount,
            'avg_cost' => $mean,
            'std_dev' => sqrt($variance),
        ];
    }

    #[Override]
    public function getQueryResults(): array
    {
        return $this->queryResults;
    }
}

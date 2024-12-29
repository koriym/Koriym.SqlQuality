<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

interface QueryStatisticsInterface
{
    /**
     * @return array{
     *     total_count: int,
     *     avg_cost: float,
     *     std_dev: float
     * }
     */
    public function calculate(array $queryResults): array;
}

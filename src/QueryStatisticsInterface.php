<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

interface QueryStatisticsInterface
{
    /**
     * Performs a calculation and returns the result
     *
     * @return array{
     *     total_count: int,
     *     avg_cost: float,
     *     std_dev: float
     * }
     */
    public function calculate(array $queryResults): array;

    /**
     * Gets the query results
     *
     * @return array<string, mixed> Query results as an associative array.
     */
    public function getQueryResults(): array;
}

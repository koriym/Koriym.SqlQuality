<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

/** @psalm-import-type QueryStatisticsResult from Types */
interface QueryStatisticsInterface
{
    /**
     * Performs a calculation and returns the result
     *
     * @return QueryStatisticsResult
     */
    public function calculate(array $queryResults): array;

    /**
     * Gets the query results
     *
     * @return array<string, mixed> Query results as an associative array.
     */
    public function getQueryResults(): array;
}

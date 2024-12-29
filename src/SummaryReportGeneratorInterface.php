<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

interface SummaryReportGeneratorInterface
{
    /**
     * @param array<string, array{
     *     cost: float,
     *     issues: array<string>,
     *     explain_result: array
     * }> $queryResults
     */
    public function generate(array $queryResults): string;
}

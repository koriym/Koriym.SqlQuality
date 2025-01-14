<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

/** @psalm-import-type AnalysisResult from Types */
interface SummaryReportGeneratorInterface
{
    /** @param array<string, AnalysisResult> $queryResults */
    public function generate(array $queryResults): string;
}

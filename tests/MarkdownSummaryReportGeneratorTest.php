<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function str_contains;

final class MarkdownSummaryReportGeneratorTest extends TestCase
{
    public function testIssuesColumnListsTheIssuesOfTheDefaultPlan(): void
    {
        $result = [
            'issues' => [['type' => 'FullTableScan'], ['type' => 'EstimateDivergence']],
            'cost' => 10.0,
            'optimizer_comparison' => [
                'with_optimizer' => ['execution_time' => 0.001],
                'without_optimizer' => ['issues' => [['type' => 'FullTableScan']], 'explain_result' => []],
                'difference' => ['cost_percent' => 0.0, 'time_percent' => 0.0],
            ],
        ];

        $report = (new MarkdownSummaryReportGenerator(new QueryStatisticsCalculator(), new StatisticalQueryLevelClassifier()))->generate(['a.sql' => $result]);

        $this->assertTrue(str_contains($report, 'FullTableScan, EstimateDivergence'));
    }
}

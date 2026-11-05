<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;
use stdClass;

use function json_decode;
use function json_encode;

use const JSON_THROW_ON_ERROR;

final class JsonReportGeneratorTest extends TestCase
{
    public function testSummaryCountsAnalyzedAndSkippedQueries(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of(
            [
                '1_full_table_scan.sql' => FakeAnalysisRun::result(497.95),
                '2_filesort.sql' => FakeAnalysisRun::result(102.05),
            ],
            ['14_not_found.sql' => 'SQL file not found: /tmp/14_not_found.sql'],
        ));

        $this->assertSame(3, $report['summary']['total_queries']);
        $this->assertSame(2, $report['summary']['analyzed']);
        $this->assertSame(1, $report['summary']['skipped']);
        $this->assertSame(300.0, $report['summary']['avg_cost']);
        $this->assertSame(600.0, $report['summary']['total_cost']);
        $this->assertSame(['14_not_found.sql' => 'SQL file not found: /tmp/14_not_found.sql'], $report['skipped']);
    }

    public function testEmptyQueriesAndSkippedSerializeAsJsonObjects(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([], []));

        $this->assertInstanceOf(stdClass::class, $report['queries']);
        $this->assertInstanceOf(stdClass::class, $report['skipped']);

        $decoded = json_decode(json_encode($report, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);

        $this->assertInstanceOf(stdClass::class, $decoded->queries);
        $this->assertInstanceOf(stdClass::class, $decoded->skipped);
    }

    public function testAvgCostIsZeroWhenEveryQueryIsSkipped(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([], ['14_not_found.sql' => 'SQL file not found']));

        $this->assertSame(0.0, $report['summary']['avg_cost']);
        $this->assertSame(0, $report['summary']['total_issues']);
    }

    public function testIssuesAreCountedBySeverity(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([
            '1_full_table_scan.sql' => FakeAnalysisRun::result(497.95, [
                FakeAnalysisRun::issue('FullTableScan', 'Critical'),
                FakeAnalysisRun::issue('IneffectiveSort', 'Warning'),
            ]),
            '19_unnecessary_distinct.sql' => FakeAnalysisRun::result(12.3, [
                FakeAnalysisRun::issue('UnnecessaryDistinct', 'Info'),
            ]),
        ]));

        $this->assertSame(3, $report['summary']['total_issues']);
        $this->assertSame(['Critical' => 1, 'Warning' => 1, 'Info' => 1], $report['summary']['issues_by_severity']);
    }

    public function testExecutionTimeIsNullForUnexecutedQuery(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([
            '20_multi_table_update.sql' => FakeAnalysisRun::result(9.1, [], false),
        ]));

        $query = $report['queries']['20_multi_table_update.sql'];

        $this->assertNull($query['execution_time_ms']);
        $this->assertFalse($query['executed']);
        $this->assertSame('execution skipped: write statement', $query['skipped_reason']);
    }

    public function testExecutionTimeIsReportedInMilliseconds(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([
            '1_full_table_scan.sql' => FakeAnalysisRun::result(50.0, [], true, 0.00592, 100.0),
        ]));

        $query = $report['queries']['1_full_table_scan.sql'];

        $this->assertSame(5.92, $query['execution_time_ms']);
        $this->assertSame(100.0, $query['optimizer_impact']['cost_without_optimizer']);
        $this->assertSame(50.0, $query['optimizer_impact']['cost_reduction_percent']);
    }

    public function testCostReductionPercentIsNegativeWhenOptimizerCostIncreases(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([
            '1_full_table_scan.sql' => FakeAnalysisRun::result(110.0, [], true, 0.00592, 100.0),
        ]));

        $query = $report['queries']['1_full_table_scan.sql'];

        $this->assertSame(-10.0, $query['optimizer_impact']['cost_reduction_percent']);
    }

    public function testCostReductionPercentIsPositiveZeroNotNegativeZeroWhenCostIsUnchanged(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([
            '1_full_table_scan.sql' => FakeAnalysisRun::result(497.95, [], true, 0.00592, 497.95),
        ]));

        $query = $report['queries']['1_full_table_scan.sql'];

        $this->assertSame(0.0, $query['optimizer_impact']['cost_reduction_percent']);
        $this->assertStringNotContainsString('-0', json_encode($query['optimizer_impact'], JSON_THROW_ON_ERROR));
    }

    public function testHiddenIssuesListTheIssuesOnlyTheNoOptimizerPlanHas(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of([
            '11_nested_loop.sql' => FakeAnalysisRun::result(
                10.0,
                [FakeAnalysisRun::issue('FullTableScan', 'Critical', 'users')],
                true,
                0.001,
                100.0,
                [FakeAnalysisRun::issue('FullTableScan', 'Critical', 'users'), FakeAnalysisRun::issue('FullTableScan', 'Critical', 'posts')],
            ),
        ]));

        $this->assertSame(['FullTableScan@posts'], $report['queries']['11_nested_loop.sql']['optimizer_impact']['hidden_issues']);
    }

    public function testBaselineIsAddedOnlyWhenGiven(): void
    {
        $run = FakeAnalysisRun::of(['a.sql' => FakeAnalysisRun::result(1.0)]);

        $this->assertArrayNotHasKey('baseline', (new JsonReportGenerator())->generate($run));
        $diff = ['new' => [], 'resolved' => [], 'persisting' => [], 'unverified' => []];
        $this->assertSame($diff, (new JsonReportGenerator())->generate($run, $diff)['baseline']);
    }
}

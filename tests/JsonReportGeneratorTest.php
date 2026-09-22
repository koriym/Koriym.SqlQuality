<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

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
        $this->assertSame(['14_not_found.sql' => 'SQL file not found: /tmp/14_not_found.sql'], $report['skipped']);
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
            '1_full_table_scan.sql' => FakeAnalysisRun::result(497.95, [], true, 0.00592),
        ]));

        $query = $report['queries']['1_full_table_scan.sql'];

        $this->assertSame(5.92, $query['execution_time_ms']);
        $this->assertSame(-44.91, $query['optimizer_impact']['cost_reduction_percent']);
    }
}

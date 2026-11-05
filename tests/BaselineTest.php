<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\InvalidBaseline;
use PHPUnit\Framework\TestCase;

final class BaselineTest extends TestCase
{
    public function testDiffSortsIssuesIntoNewResolvedPersistingAndUnverified(): void
    {
        $baseline = Baseline::fromReport([
            'queries' => [
                'a.sql' => [
                    'issues' => [
                        ['id' => 'FullTableScan@posts', 'severity' => 'Critical'],
                        ['id' => 'IneffectiveSort@posts', 'severity' => 'Warning'],
                    ],
                ],
                'gone.sql' => ['issues' => [['id' => 'FullTableScan@users', 'severity' => 'Critical']]],
            ],
        ]);

        $diff = $baseline->diff(FakeAnalysisRun::of([
            'a.sql' => FakeAnalysisRun::result(1.0, [
                FakeAnalysisRun::issue('FullTableScan', 'Critical'),
                FakeAnalysisRun::issue('DeepOffset', 'Warning'),
            ]),
            'new.sql' => FakeAnalysisRun::result(1.0, [FakeAnalysisRun::issue('OrderByRand', 'Warning')]),
        ]));

        $this->assertSame([
            ['file' => 'a.sql', 'id' => 'DeepOffset@posts', 'severity' => 'Warning'],
            ['file' => 'new.sql', 'id' => 'OrderByRand@posts', 'severity' => 'Warning'],
        ], $diff['new']);
        $this->assertSame([['file' => 'a.sql', 'id' => 'IneffectiveSort@posts', 'severity' => 'Warning']], $diff['resolved']);
        $this->assertSame([['file' => 'a.sql', 'id' => 'FullTableScan@posts', 'severity' => 'Critical']], $diff['persisting']);
        $this->assertSame(['gone.sql'], $diff['unverified']);
    }

    public function testAReportWithoutIssueIdsIsRejected(): void
    {
        $this->expectException(InvalidBaseline::class);

        Baseline::fromReport(['queries' => ['a.sql' => ['issues' => [['type' => 'FullTableScan', 'severity' => 'Critical']]]]]);
    }

    public function testSomethingThatIsNotAReportIsRejected(): void
    {
        $this->expectException(InvalidBaseline::class);

        Baseline::fromReport(['summary' => []]);
    }

    public function testAMissingFileIsRejected(): void
    {
        $this->expectException(InvalidBaseline::class);

        Baseline::fromFile(__DIR__ . '/no_such_baseline.json');
    }
}

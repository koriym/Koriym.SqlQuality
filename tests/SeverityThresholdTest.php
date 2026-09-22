<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\InvalidSeverityThreshold;
use PHPUnit\Framework\TestCase;

final class SeverityThresholdTest extends TestCase
{
    public function testRejectsUnknownLevel(): void
    {
        $this->expectException(InvalidSeverityThreshold::class);
        $this->expectExceptionMessage('bogus');

        new SeverityThreshold('bogus');
    }

    public function testAcceptsLevelInAnyCase(): void
    {
        $run = FakeAnalysisRun::of(['a.sql' => FakeAnalysisRun::result(10.0, [FakeAnalysisRun::issue('FullTableScan', 'Critical')])]);

        $this->assertTrue((new SeverityThreshold('CRITICAL'))->isMetBy($run));
    }

    public function testCriticalIsNotMetByWarning(): void
    {
        $run = FakeAnalysisRun::of(['a.sql' => FakeAnalysisRun::result(10.0, [FakeAnalysisRun::issue('IneffectiveSort', 'Warning')])]);

        $this->assertFalse((new SeverityThreshold('critical'))->isMetBy($run));
    }

    public function testWarningIsMetByCritical(): void
    {
        $run = FakeAnalysisRun::of(['a.sql' => FakeAnalysisRun::result(10.0, [FakeAnalysisRun::issue('FullTableScan', 'Critical')])]);

        $this->assertTrue((new SeverityThreshold('warning'))->isMetBy($run));
    }

    public function testInfoIsMetByInfo(): void
    {
        $run = FakeAnalysisRun::of(['a.sql' => FakeAnalysisRun::result(10.0, [FakeAnalysisRun::issue('UnnecessaryDistinct', 'Info')])]);

        $this->assertTrue((new SeverityThreshold('info'))->isMetBy($run));
    }

    public function testWarningIsNotMetByInfo(): void
    {
        $run = FakeAnalysisRun::of(['a.sql' => FakeAnalysisRun::result(10.0, [FakeAnalysisRun::issue('UnnecessaryDistinct', 'Info')])]);

        $this->assertFalse((new SeverityThreshold('warning'))->isMetBy($run));
    }

    public function testSkippedFilesDoNotMeetAnyLevel(): void
    {
        $run = FakeAnalysisRun::of([], ['a.sql' => 'SQL file not found: /tmp/a.sql']);

        $this->assertFalse((new SeverityThreshold('info'))->isMetBy($run));
    }
}

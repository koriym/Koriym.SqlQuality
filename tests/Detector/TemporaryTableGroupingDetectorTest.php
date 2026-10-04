<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use PHPUnit\Framework\TestCase;

final class TemporaryTableGroupingDetectorTest extends TestCase
{
    public function testReportsPathOfTheTemporaryTable(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(Fixture::load('7_temporary_table_grouping.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame(['ordering_operation'], $findings[0]->evidence['path']);
    }

    public function testReportsNothingWithoutTemporaryTable(): void
    {
        $this->assertSame([], (new TemporaryTableGroupingDetector())->detect(Fixture::load('1_full_table_scan.sql')));
    }
}

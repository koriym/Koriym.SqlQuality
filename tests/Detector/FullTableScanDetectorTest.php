<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use PHPUnit\Framework\TestCase;

final class FullTableScanDetectorTest extends TestCase
{
    public function testReportsEachTableScannedInFull(): void
    {
        $findings = (new FullTableScanDetector())->detect(Fixture::load('1_full_table_scan.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('posts', $findings[0]->evidence['table_name']);
        $this->assertSame(4956, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertArrayNotHasKey('possible_keys', $findings[0]->evidence);
    }

    public function testReportsNothingWithoutTableAccess(): void
    {
        $this->assertSame([], (new FullTableScanDetector())->detect(Fixture::load('12_select1.sql')));
    }
}

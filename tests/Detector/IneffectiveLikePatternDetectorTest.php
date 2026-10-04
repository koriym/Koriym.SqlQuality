<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class IneffectiveLikePatternDetectorTest extends TestCase
{
    public function testReportsEveryLeadingWildcardLikeOnAFullScan(): void
    {
        $findings = (new IneffectiveLikePatternDetector())->detect(Fixture::load('5_multiple_wildcard_like.sql'));

        $this->assertCount(2, $findings);
        $this->assertSame(['title', 'content'], [$findings[0]->evidence['column'], $findings[1]->evidence['column']]);
        $this->assertSame("'%keyword%'", $findings[0]->evidence['pattern']);
        $this->assertSame('ALL', $findings[0]->evidence['access_type']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testReportsALeadingWildcardLikeOnARangeScanWithALowFilter(): void
    {
        $findings = (new IneffectiveLikePatternDetector())->detect(Fixture::load('27_range_scan_low_filter.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('comments', $findings[0]->evidence['table_name']);
        $this->assertSame('content', $findings[0]->evidence['column']);
        $this->assertSame('range', $findings[0]->evidence['access_type']);
        $this->assertSame(11.11, (float) $findings[0]->evidence['filtered']);
    }

    public function testIgnoresAPrefixMatch(): void
    {
        $this->assertSame([], (new IneffectiveLikePatternDetector())->detect(self::like("(`test`.`posts`.`title` like 'key%')", 'ALL', 20.0)));
    }

    public function testIgnoresALookupThatKeepsMostRows(): void
    {
        $this->assertSame([], (new IneffectiveLikePatternDetector())->detect(self::like("(`test`.`posts`.`title` like '%key%')", 'ref', 50.0)));
    }

    private static function like(string $attachedCondition, string $accessType, float $filtered): QueryContext
    {
        return new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'posts', 'access_type' => $accessType, 'attached_condition' => $attachedCondition, 'rows_examined_per_scan' => 4956, 'filtered' => $filtered]]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class IneffectiveRangeScanDetectorTest extends TestCase
{
    public function testReportsARangeScanExaminingManyRowsWithALowFilter(): void
    {
        $findings = (new IneffectiveRangeScanDetector())->detect(Fixture::load('27_range_scan_low_filter.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('comments', $findings[0]->evidence['table_name']);
        $this->assertSame('range', $findings[0]->evidence['access_type']);
        $this->assertSame('idx_comments_post_id', $findings[0]->evidence['key']);
        $this->assertSame(['idx_comments_post_id', 'idx_comments_post_created'], $findings[0]->evidence['possible_keys']);
        $this->assertSame(2003, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testReportsEveryIndexMerge(): void
    {
        $findings = (new IneffectiveRangeScanDetector())->detect(self::scan('index_merge', 50, 100.0));

        $this->assertCount(1, $findings);
        $this->assertSame('index_merge', $findings[0]->evidence['access_type']);
    }

    public function testIgnoresAFullScanWithAnInList(): void
    {
        $this->assertSame([], (new IneffectiveRangeScanDetector())->detect(Fixture::load('9_inefficient_in_query.sql')));
    }

    public function testIgnoresAFullScanWithARangeCondition(): void
    {
        $this->assertSame([], (new IneffectiveRangeScanDetector())->detect(Fixture::load('16_ineffective_range_scan.sql')));
    }

    public function testIgnoresARangeScanExaminingFewRows(): void
    {
        $this->assertSame([], (new IneffectiveRangeScanDetector())->detect(self::scan('range', 999, 10.0)));
    }

    public function testIgnoresARangeScanKeepingMostRows(): void
    {
        $this->assertSame([], (new IneffectiveRangeScanDetector())->detect(self::scan('range', 2000, 20.0)));
    }

    public function testIgnoresAMaterializedDerivedTable(): void
    {
        $context = new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'd', 'access_type' => 'range', 'possible_keys' => ['idx'], 'key' => 'idx', 'rows_examined_per_scan' => 2000, 'filtered' => 10.0, 'materialized_from_subquery' => ['using_temporary_table' => true]]]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        );

        $this->assertSame([], (new IneffectiveRangeScanDetector())->detect($context));
    }

    private static function scan(string $accessType, int $rowsExamined, float $filtered): QueryContext
    {
        return new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'comments', 'access_type' => $accessType, 'possible_keys' => ['idx_comments_post_id', 'idx_comments_user_id'], 'key' => 'idx_comments_post_id', 'rows_examined_per_scan' => $rowsExamined, 'filtered' => $filtered, 'attached_condition' => '(`test`.`comments`.`post_id` between 1 and 1000)']]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        );
    }
}

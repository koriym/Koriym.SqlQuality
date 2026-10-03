<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class FunctionInvalidatesIndexDetectorTest extends TestCase
{
    public function testReportsAFunctionOnAnIndexedColumnWithADateRangeRewrite(): void
    {
        $findings = (new FunctionInvalidatesIndexDetector())->detect(Fixture::load('3_function_on_indexed_column.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('posts', $findings[0]->evidence['table_name']);
        $this->assertSame('created_at', $findings[0]->evidence['column']);
        $this->assertSame('cast', $findings[0]->evidence['function']);
        $this->assertSame(['idx_posts_status_created'], $findings[0]->evidence['indexes']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind'] ?? null);
        $this->assertSame("created_at >= '2024-01-01' AND created_at < '2024-01-01' + INTERVAL 1 DAY", $findings[0]->suggestion['sql'] ?? null);
    }

    public function testIgnoresAFunctionOnAColumnOutsideEveryIndex(): void
    {
        $this->assertSame([], (new FunctionInvalidatesIndexDetector())->detect(self::condition("(cast(`test`.`posts`.`title` as char charset utf8mb4) = 'x')")));
    }

    public function testSuggestsNoSqlForAFunctionOtherThanDate(): void
    {
        $findings = (new FunctionInvalidatesIndexDetector())->detect(self::condition('(year(`test`.`posts`.`created_at`) = 2024)'));

        $this->assertCount(1, $findings);
        $this->assertSame('year', $findings[0]->evidence['function']);
        $this->assertArrayNotHasKey('sql', $findings[0]->suggestion ?? []);
    }

    public function testReportsAColumnUnderAnOr(): void
    {
        $findings = (new FunctionInvalidatesIndexDetector())->detect(self::condition("((cast(`test`.`posts`.`created_at` as date) = '2024-01-01') or (`test`.`posts`.`status` = 'draft'))"));

        $this->assertCount(1, $findings);
        $this->assertSame('created_at', $findings[0]->evidence['column']);
    }

    public function testIgnoresAnInList(): void
    {
        $this->assertSame([], (new FunctionInvalidatesIndexDetector())->detect(Fixture::load('9_inefficient_in_query.sql')));
    }

    public function testDedupesRepeatedFunctionCallsOnTheSameColumn(): void
    {
        $findings = (new FunctionInvalidatesIndexDetector())->detect(self::condition("((cast(`test`.`posts`.`created_at` as date) = '2024-01-01') or (cast(`test`.`posts`.`created_at` as date) = '2024-01-02'))"));

        $this->assertCount(1, $findings);
        $this->assertSame('created_at', $findings[0]->evidence['column']);
    }

    private static function condition(string $attachedCondition): QueryContext
    {
        return new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'posts', 'access_type' => 'ALL', 'attached_condition' => $attachedCondition, 'rows_examined_per_scan' => 4956, 'filtered' => 100.0]]],
            explainAnalyze: null,
            warnings: [],
            schema: ['posts' => ['columns' => [], 'indexes' => [['INDEX_NAME' => 'idx_posts_status_created', 'COLUMN_NAME' => 'status', 'NON_UNIQUE' => 1, 'SEQ_IN_INDEX' => 1, 'CARDINALITY' => 3], ['INDEX_NAME' => 'idx_posts_status_created', 'COLUMN_NAME' => 'created_at', 'NON_UNIQUE' => 1, 'SEQ_IN_INDEX' => 2, 'CARDINALITY' => 4956]], 'status' => ['table_rows' => 4956]]],
        );
    }
}

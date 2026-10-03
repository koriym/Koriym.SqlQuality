<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class OrderByRandDetectorTest extends TestCase
{
    public function testOrderByRandIsDetectedWithOrderingEvidence(): void
    {
        $findings = (new OrderByRandDetector())->detect(Fixture::load('30_order_by_rand.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame(2478, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertTrue($findings[0]->evidence['using_temporary_table']);
        $this->assertTrue($findings[0]->evidence['using_filesort']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind']);
    }

    public function testQueryWithoutOrderByRandIsNotDetected(): void
    {
        $this->assertSame([], (new OrderByRandDetector())->detect(new QueryContext(
            sql: 'SELECT * FROM posts ORDER BY created_at DESC LIMIT 5',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        )));
    }

    public function testIgnoresOrderByRandMentionedOnlyInAComment(): void
    {
        $this->assertSame([], (new OrderByRandDetector())->detect(new QueryContext(
            sql: "-- Problem: ORDER BY RAND() was considered and rejected here\nSELECT * FROM posts ORDER BY created_at DESC LIMIT 5",
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        )));
    }

    public function testRowsExaminedAtCriticalThresholdIsCritical(): void
    {
        $findings = (new OrderByRandDetector())->detect(new QueryContext(
            sql: 'SELECT * FROM posts ORDER BY RAND() LIMIT 5',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'ordering_operation' => [
                        'using_filesort' => true,
                        'table' => ['table_name' => 'posts', 'access_type' => 'ALL', 'rows_examined_per_scan' => 10000],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame('Critical', $findings[0]->severity);
    }
}

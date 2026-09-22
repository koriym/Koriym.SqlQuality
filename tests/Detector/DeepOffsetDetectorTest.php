<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class DeepOffsetDetectorTest extends TestCase
{
    public function testCommaFormOffsetIsDetectedWithOrderingEvidence(): void
    {
        $findings = (new DeepOffsetDetector())->detect(Fixture::load('29_deep_offset.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame(10000, $findings[0]->evidence['offset']);
        $this->assertSame(10, $findings[0]->evidence['limit']);
        $this->assertSame(['using_filesort' => true, 'rows_examined_per_scan' => 4956], $findings[0]->evidence['ordering']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind']);
    }

    public function testOffsetBelowThresholdIsNotDetected(): void
    {
        $findings = (new DeepOffsetDetector())->detect(new QueryContext(
            sql: 'SELECT * FROM posts ORDER BY created_at DESC LIMIT 999, 10',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame([], $findings);
    }

    public function testOffsetKeywordFormIsDetected(): void
    {
        $findings = (new DeepOffsetDetector())->detect(new QueryContext(
            sql: 'SELECT * FROM posts ORDER BY created_at DESC LIMIT 10 OFFSET 5000',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(5000, $findings[0]->evidence['offset']);
        $this->assertSame(10, $findings[0]->evidence['limit']);
        $this->assertNull($findings[0]->evidence['ordering']);
        $this->assertNull($findings[0]->severity);
    }

    public function testOffsetAtCriticalThresholdIsCritical(): void
    {
        $findings = (new DeepOffsetDetector())->detect(new QueryContext(
            sql: 'SELECT * FROM posts ORDER BY created_at DESC LIMIT 100000, 10',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame('Critical', $findings[0]->severity);
    }
}

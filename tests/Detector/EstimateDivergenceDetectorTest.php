<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class EstimateDivergenceDetectorTest extends TestCase
{
    public function testDivergingNodeIsDetectedWithTableResolvedFromAlias(): void
    {
        $findings = (new EstimateDivergenceDetector())->detect(new QueryContext(
            sql: "SELECT p.* FROM posts p WHERE p.status = 'published'",
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: "-> Index lookup on p using idx_posts_status (status='published')  (cost=50 rows=50) (actual time=0.02..5.1 rows=500 loops=1)",
            warnings: [],
            schema: [],
        ));

        $this->assertCount(1, $findings);
        $evidence = $findings[0]->evidence;
        $this->assertSame(50.0, $evidence['estimated_rows']);
        $this->assertSame(500.0, $evidence['actual_rows']);
        $this->assertSame(1, $evidence['loops']);
        $this->assertSame(10.0, $evidence['ratio']);
        $this->assertSame('posts', $evidence['table']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('review', $findings[0]->suggestion['kind']);
        $this->assertStringContainsString('ANALYZE TABLE posts', $findings[0]->suggestion['description']);
    }

    public function testRatioBelowThresholdIsNotDetected(): void
    {
        $findings = (new EstimateDivergenceDetector())->detect(new QueryContext(
            sql: 'SELECT p.* FROM posts p',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: '-> Table scan on p  (cost=480 rows=480) (actual time=0.02..5.1 rows=500 loops=1)',
            warnings: [],
            schema: [],
        ));

        $this->assertSame([], $findings);
    }

    public function testNeverExecutedNodeIsNotDetected(): void
    {
        $findings = (new EstimateDivergenceDetector())->detect(new QueryContext(
            sql: 'SELECT * FROM posts WHERE user_id = 1',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: '-> Index lookup on posts using idx_posts_user_id (user_id=users.id)  (cost=1.26 rows=5.01) (never executed)',
            warnings: [],
            schema: [],
        ));

        $this->assertSame([], $findings);
    }

    public function testNullExplainAnalyzeIsNotDetected(): void
    {
        $findings = (new EstimateDivergenceDetector())->detect(new QueryContext(
            sql: 'UPDATE posts SET status = ?',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame([], $findings);
    }

    public function testUnresolvableAliasProducesNullTableAndGenericSuggestion(): void
    {
        $findings = (new EstimateDivergenceDetector())->detect(new QueryContext(
            sql: 'SELECT status, COUNT(*) FROM posts GROUP BY status',
            explain: ['query_block' => ['select_id' => 1]],
            explainAnalyze: '-> Table scan on <temporary>  (cost=10 rows=10) (actual time=1..2 rows=1000 loops=1)',
            warnings: [],
            schema: [],
        ));

        $this->assertCount(1, $findings);
        $this->assertNull($findings[0]->evidence['table']);
        $this->assertSame('review', $findings[0]->suggestion['kind']);
        $this->assertStringNotContainsString('ANALYZE TABLE', $findings[0]->suggestion['description']);
    }
}

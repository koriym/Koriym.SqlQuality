<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function count;
use function preg_match_all;

final class ExplainAnalyzeParserTest extends TestCase
{
    public function testParsesCostAndActualAroundParenthesizedOperation(): void
    {
        $nodes = (new ExplainAnalyzeParser())->parse("-> Index lookup on p using idx_posts_status_created (status='published')  (cost=273 rows=2478) (actual time=0.0275..3.99 rows=4500 loops=1)");

        $this->assertSame([
            [
                'depth' => 0,
                'operation' => "Index lookup on p using idx_posts_status_created (status='published')",
                'estimated_cost' => 273.0,
                'estimated_rows' => 2478.0,
                'actual_time_first' => 0.0275,
                'actual_time_last' => 3.99,
                'actual_rows' => 4500.0,
                'loops' => 1,
                'never_executed' => false,
            ],
        ], $nodes);
    }

    public function testParsesNodeWithoutCost(): void
    {
        $nodes = (new ExplainAnalyzeParser())->parse('    -> Table scan on <temporary>  (actual time=17.1..17.6 rows=4500 loops=1)');

        $this->assertCount(1, $nodes);
        $this->assertSame(1, $nodes[0]['depth']);
        $this->assertSame('Table scan on <temporary>', $nodes[0]['operation']);
        $this->assertNull($nodes[0]['estimated_cost']);
        $this->assertNull($nodes[0]['estimated_rows']);
        $this->assertSame(17.1, $nodes[0]['actual_time_first']);
        $this->assertSame(4500.0, $nodes[0]['actual_rows']);
    }

    public function testParsesExponentAndFractionalNumbers(): void
    {
        $parser = new ExplainAnalyzeParser();

        $join = $parser->parse('-> Nested loop inner join  (cost=218125 rows=1.98e+6) (actual time=0.141..0.143 rows=10 loops=1)')[0];
        $lookup = $parser->parse('        -> Covering index lookup on comments using idx_comments_post_created (post_id=posts.id)  (cost=0.251 rows=2.27) (actual time=964e-6..0.00127 rows=1.98 loops=4500)')[0];

        $this->assertSame(1980000.0, $join['estimated_rows']);
        $this->assertSame(10.0, $join['actual_rows']);
        $this->assertSame(2, $lookup['depth']);
        $this->assertSame(2.27, $lookup['estimated_rows']);
        $this->assertSame(0.000964, $lookup['actual_time_first']);
        $this->assertSame(1.98, $lookup['actual_rows']);
        $this->assertSame(4500, $lookup['loops']);
    }

    public function testParsesNeverExecutedNode(): void
    {
        $nodes = (new ExplainAnalyzeParser())->parse('    -> Index lookup on posts using idx_posts_user_id (user_id=users.id)  (cost=1.26 rows=5.01) (never executed)');

        $this->assertSame([
            [
                'depth' => 1,
                'operation' => 'Index lookup on posts using idx_posts_user_id (user_id=users.id)',
                'estimated_cost' => 1.26,
                'estimated_rows' => 5.01,
                'actual_time_first' => null,
                'actual_time_last' => null,
                'actual_rows' => null,
                'loops' => null,
                'never_executed' => true,
            ],
        ], $nodes);
    }

    public function testTakesTotalOfCostRange(): void
    {
        $nodes = (new ExplainAnalyzeParser())->parse("-> Table scan on <union temporary>  (cost=2505..2609 rows=8109) (actual time=11.4..12.4 rows=13409 loops=1)\n    -> Rows fetched before execution  (cost=0..0 rows=1) (actual time=0..0 rows=1 loops=1)");

        $this->assertSame(2609.0, $nodes[0]['estimated_cost']);
        $this->assertSame(0.0, $nodes[1]['estimated_cost']);
        $this->assertSame(1.0, $nodes[1]['estimated_rows']);
    }

    public function testKeepsNodeWithoutMeasurementsAndSkipsOtherLines(): void
    {
        $nodes = (new ExplainAnalyzeParser())->parse("-> Select #2 (subquery in projection; dependent)\n\nnot a plan line\n");

        $this->assertCount(1, $nodes);
        $this->assertSame('Select #2 (subquery in projection; dependent)', $nodes[0]['operation']);
        $this->assertNull($nodes[0]['estimated_cost']);
        $this->assertNull($nodes[0]['actual_rows']);
        $this->assertFalse($nodes[0]['never_executed']);
    }

    /** @return iterable<string, array{string}> */
    public static function analyzedFixtureProvider(): iterable
    {
        foreach (Fixture::names() as $name) {
            if (Fixture::load($name)->explainAnalyze === null) {
                continue;
            }

            yield $name => [$name];
        }
    }

    /** @dataProvider analyzedFixtureProvider */
    public function testEveryPlanLineOfFixtureBecomesANode(string $name): void
    {
        $text = (string) Fixture::load($name)->explainAnalyze;

        $this->assertSame(preg_match_all('/^ *-> /m', $text), count((new ExplainAnalyzeParser())->parse($text)));
    }
}

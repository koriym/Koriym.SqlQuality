<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class UnnecessaryDistinctDetectorTest extends TestCase
{
    private const ORDERS = ['orders' => ['columns' => [['COLUMN_NAME' => 'id', 'COLUMN_KEY' => 'PRI'], ['COLUMN_NAME' => 'user_id', 'COLUMN_KEY' => 'MUL']]]];

    public function testReportsDistinctOverThePrimaryKey(): void
    {
        $findings = (new UnnecessaryDistinctDetector())->detect(Fixture::load('19_unnecessary_distinct.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('orders', $findings[0]->evidence['table_name']);
        $this->assertSame(['id'], $findings[0]->evidence['primary_key']);
        $this->assertSame(['id', 'created_at'], $findings[0]->evidence['select_list']);
        $this->assertNull($findings[0]->severity);
    }

    public function testSuggestsTheStatementWithoutDistinct(): void
    {
        $findings = (new UnnecessaryDistinctDetector())->detect(Fixture::load('19_unnecessary_distinct.sql'));

        $this->assertSame('rewrite', $findings[0]->suggestion['kind'] ?? null);
        $this->assertSame("SELECT id, created_at\nFROM orders\nWHERE user_id = 1\nORDER BY created_at;", $findings[0]->suggestion['sql'] ?? null);
    }

    public function testIgnoresDistinctWithoutThePrimaryKey(): void
    {
        $this->assertSame([], (new UnnecessaryDistinctDetector())->detect(Fixture::load('18_select_distinct.sql')));
    }

    public function testIgnoresAJoin(): void
    {
        $explain = ['query_block' => ['select_id' => 1, 'duplicates_removal' => ['nested_loop' => [['table' => ['table_name' => 'o', 'access_type' => 'ALL']], ['table' => ['table_name' => 'u', 'access_type' => 'eq_ref']]]]]];

        $this->assertSame([], (new UnnecessaryDistinctDetector())->detect(self::context('SELECT DISTINCT o.id, u.name FROM orders o JOIN users u ON u.id = o.user_id', $explain)));
    }

    public function testDoesNotJudgeAFunctionInTheSelectList(): void
    {
        $this->assertSame([], (new UnnecessaryDistinctDetector())->detect(self::context('SELECT DISTINCT id, DATE(created_at) FROM orders', self::distinctScan())));
    }

    public function testIgnoresAPlanWithoutDuplicateRemoval(): void
    {
        $explain = ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'orders', 'access_type' => 'index']]];

        $this->assertSame([], (new UnnecessaryDistinctDetector())->detect(self::context('SELECT DISTINCT id FROM orders', $explain)));
    }

    public function testTreatsStarAsSelectingThePrimaryKey(): void
    {
        $findings = (new UnnecessaryDistinctDetector())->detect(self::context('SELECT DISTINCT * FROM orders WHERE user_id = 1', self::distinctScan()));

        $this->assertCount(1, $findings);
        $this->assertSame('SELECT * FROM orders WHERE user_id = 1', $findings[0]->suggestion['sql'] ?? null);
    }

    public function testRequiresEveryColumnOfACompositeKey(): void
    {
        $schema = ['post_tags' => ['columns' => [['COLUMN_NAME' => 'post_id', 'COLUMN_KEY' => 'PRI'], ['COLUMN_NAME' => 'tag_id', 'COLUMN_KEY' => 'PRI']]]];

        $this->assertSame([], (new UnnecessaryDistinctDetector())->detect(self::context('SELECT DISTINCT post_id FROM post_tags', self::distinctScan('post_tags'), $schema)));
    }

    public function testSeesThroughALeadingBlockComment(): void
    {
        $findings = (new UnnecessaryDistinctDetector())->detect(self::context('/* report */ SELECT DISTINCT id FROM orders', self::distinctScan()));

        $this->assertCount(1, $findings);
    }

    public function testKeepsADashMarkerInsideAStringLiteral(): void
    {
        $findings = (new UnnecessaryDistinctDetector())->detect(self::context("SELECT DISTINCT id FROM orders WHERE note = '--admin'", self::distinctScan()));

        $this->assertCount(1, $findings);
        $this->assertSame("SELECT id FROM orders WHERE note = '--admin'", $findings[0]->suggestion['sql'] ?? null);
    }

    /** @return array<string, mixed> */
    private static function distinctScan(string $table = 'orders'): array
    {
        return ['query_block' => ['select_id' => 1, 'duplicates_removal' => ['using_filesort' => false, 'table' => ['table_name' => $table, 'access_type' => 'ALL']]]];
    }

    /**
     * @param array<string, mixed> $explain
     * @param array<string, mixed> $schema
     */
    private static function context(string $sql, array $explain, array $schema = self::ORDERS): QueryContext
    {
        return new QueryContext(sql: $sql, explain: $explain, explainAnalyze: null, warnings: [], schema: $schema);
    }
}

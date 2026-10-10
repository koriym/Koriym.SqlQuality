<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class IneffectiveJoinDetectorTest extends TestCase
{
    public function testReportsAnInnerTableJoinedThroughAJoinBuffer(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(Fixture::load('26_join_without_index.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('o', $findings[0]->evidence['table_name']);
        $this->assertSame('ALL', $findings[0]->evidence['access_type']);
        $this->assertSame('hash join', $findings[0]->evidence['using_join_buffer']);
        $this->assertNull($findings[0]->severity);
    }

    public function testSuggestsAnIndexOnThisTablesSideOfTheJoinCondition(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(Fixture::load('26_join_without_index.sql'));

        $this->assertSame('CREATE INDEX idx_orders_reference_code ON orders (reference_code)', $findings[0]->suggestion['ddl'] ?? null);
    }

    public function testIgnoresTheDrivingTable(): void
    {
        // users is scanned in full but drives the loop; posts is reached by ref.
        $this->assertSame([], (new IneffectiveJoinDetector())->detect(Fixture::load('11_nested_loop.sql')));
    }

    public function testIgnoresInnerTablesReachedByIndexLookup(): void
    {
        $this->assertSame([], (new IneffectiveJoinDetector())->detect(Fixture::load('4_no_index_on_join.sql')));
    }

    public function testReportsAnInnerFullIndexScan(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(self::join(['table_name' => 'comments', 'access_type' => 'index', 'rows_examined_per_scan' => 10000]));

        $this->assertCount(1, $findings);
        $this->assertSame('comments', $findings[0]->evidence['table_name']);
        $this->assertSame(10000, $findings[0]->evidence['rows_examined_per_scan']);
    }

    public function testReportsAnInnerLookupThatStillUsesAJoinBuffer(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(self::join(['table_name' => 'comments', 'access_type' => 'ref', 'using_join_buffer' => 'Batched Key Access']));

        $this->assertCount(1, $findings);
    }

    public function testSuggestsReviewWithoutAJoinCondition(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(self::join(['table_name' => 'comments', 'access_type' => 'ALL']));

        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testSuggestsReviewWhenTheJoinColumnIsAlreadyIndexed(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(self::join(
            ['table_name' => 'comments', 'access_type' => 'ALL', 'attached_condition' => '(`test`.`comments`.`post_id` = `test`.`posts`.`id`)'],
            schema: ['comments' => ['indexes' => [['INDEX_NAME' => 'idx_comments_post_id', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'post_id']]]],
        ));

        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testDoesNotCombineSeparateSingleTableNestedLoops(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    ['table' => ['table_name' => 'posts', 'access_type' => 'ALL', 'rows_examined_per_scan' => 5000]],
                ],
                'select_list_subqueries' => [
                    [
                        'query_block' => [
                            'select_id' => 2,
                            'nested_loop' => [
                                ['table' => ['table_name' => 'users', 'access_type' => 'ALL', 'rows_examined_per_scan' => 5000]],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    public function testDoesNotTreatNestedSubqueryTableAsJoinMember(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    ['table' => ['table_name' => 'users', 'access_type' => 'ref', 'rows_examined_per_scan' => 1]],
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'access_type' => 'ref',
                            'rows_examined_per_scan' => 1,
                            'materialized_from_subquery' => [
                                'query_block' => [
                                    'select_id' => 2,
                                    'table' => ['table_name' => 'comments', 'access_type' => 'ALL', 'rows_examined_per_scan' => 5000],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    /**
     * @param array<string, mixed> $inner
     * @param array<string, mixed> $schema
     */
    private static function join(array $inner, array $schema = []): QueryContext
    {
        return self::context([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    ['table' => ['table_name' => 'posts', 'access_type' => 'ref', 'rows_examined_per_scan' => 1]],
                    ['table' => $inner],
                ],
            ],
        ], $schema);
    }

    /**
     * @param array<string, mixed> $explain
     * @param array<string, mixed> $schema
     */
    private static function context(array $explain, array $schema = []): QueryContext
    {
        return new QueryContext(sql: '', explain: $explain, explainAnalyze: null, warnings: [], schema: $schema);
    }

    public function testIgnoresATableFunction(): void
    {
        $findings = (new IneffectiveJoinDetector())->detect(new QueryContext(
            sql: '',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'nested_loop' => [
                        ['table' => ['table_name' => 'articles', 'access_type' => 'index', 'key' => 'PRIMARY', 'rows_examined_per_scan' => 45345]],
                        ['table' => ['table_name' => 'reducebody', 'access_type' => 'ALL', 'table_function' => 'json_table', 'rows_examined_per_scan' => 2, 'rows_produced_per_join' => 90690]],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame([], $findings);
    }
}

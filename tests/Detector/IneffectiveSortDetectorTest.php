<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

use function array_map;

final class IneffectiveSortDetectorTest extends TestCase
{
    public function testReportsAFilesortOverAFullTableScan(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(Fixture::load('16_ineffective_range_scan.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('orders', $findings[0]->evidence['table_name']);
        $this->assertSame('ALL', $findings[0]->evidence['access_type']);
        $this->assertSame(2000, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertNull($findings[0]->evidence['key']);
        $this->assertSame([], $findings[0]->evidence['index_columns']);
        $this->assertFalse($findings[0]->evidence['using_temporary_table']);
        $this->assertNull($findings[0]->severity);
    }

    public function testSuggestsAnIndexOnTheOrderByColumns(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(Fixture::load('16_ineffective_range_scan.sql'));

        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
        $this->assertStringContainsString('orders (created_at)', $findings[0]->suggestion['description'] ?? '');
    }

    public function testUsesTheRealOrderByNotAMentionInAComment(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(new QueryContext(
            sql: "-- Sort users by ORDER BY name for display\nSELECT * FROM posts WHERE status = 'published' ORDER BY created_at",
            explain: ['query_block' => ['select_id' => 1, 'ordering_operation' => ['using_filesort' => true, 'table' => ['table_name' => 'posts', 'access_type' => 'ALL', 'rows_examined_per_scan' => 2000]]]],
            explainAnalyze: null,
            warnings: [],
            schema: ['posts' => ['columns' => [['COLUMN_NAME' => 'created_at', 'DATA_TYPE' => 'datetime']], 'indexes' => []]],
        ));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('posts (created_at)', $findings[0]->suggestion['description'] ?? '');
    }

    public function testNamesAnExistingIndexTheOptimizerDidNotUse(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(Fixture::load('9_inefficient_in_query.sql'));

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('idx_posts_status_created', $findings[0]->suggestion['description'] ?? '');
    }

    public function testReportsAFilesortAboveAGroupingOperation(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(Fixture::load('7_temporary_table_grouping.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('orders', $findings[0]->evidence['table_name']);
        $this->assertSame('index', $findings[0]->evidence['access_type']);
        $this->assertTrue($findings[0]->evidence['using_temporary_table']);
        // order_count is an aggregate alias, not a column an index could order by.
        $this->assertStringNotContainsString('order_count', $findings[0]->suggestion['description'] ?? '');
    }

    public function testIgnoresOrderingResolvedByAnIndex(): void
    {
        $this->assertSame([], (new IneffectiveSortDetector())->detect(Fixture::load('2_filesort.sql')));
    }

    public function testIgnoresAFilesortOverAFewRows(): void
    {
        $this->assertSame([], (new IneffectiveSortDetector())->detect(Fixture::load('19_unnecessary_distinct.sql')));
    }

    public function testReportsAFilesortOverALargeIndexLookup(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(self::context(
            ['query_block' => ['select_id' => 1, 'ordering_operation' => ['using_filesort' => true, 'table' => ['table_name' => 'posts', 'access_type' => 'ref', 'rows_examined_per_scan' => 1500, 'key' => 'idx_posts_status', 'used_key_parts' => ['status']]]]],
            ['posts' => ['indexes' => [['INDEX_NAME' => 'idx_posts_status', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'status']]]],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(['status'], $findings[0]->evidence['index_columns']);
    }

    public function testReportsEveryFilesortInTheTree(): void
    {
        $findings = (new IneffectiveSortDetector())->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'ordering_operation' => [
                    'using_filesort' => true,
                    'table' => ['table_name' => 'posts', 'access_type' => 'ALL', 'rows_examined_per_scan' => 5000],
                ],
                'select_list_subqueries' => [
                    [
                        'query_block' => [
                            'select_id' => 2,
                            'ordering_operation' => [
                                'using_filesort' => true,
                                'table' => ['table_name' => 'comments', 'access_type' => 'ALL', 'rows_examined_per_scan' => 10000],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertSame(['posts', 'comments'], array_map(static fn (Finding $finding): string => (string) $finding->evidence['table_name'], $findings));
    }

    /**
     * @param array<string, mixed> $explain
     * @param array<string, mixed> $schema
     */
    private static function context(array $explain, array $schema = []): QueryContext
    {
        return new QueryContext(sql: '', explain: $explain, explainAnalyze: null, warnings: [], schema: $schema);
    }
}

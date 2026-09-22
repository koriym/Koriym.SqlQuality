<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class IneffectiveUnionDetectorTest extends TestCase
{
    public function testReportsAUnionRequiringTemporaryTableAndFilesort(): void
    {
        $findings = (new IneffectiveUnionDetector())->detect(Fixture::load('23_ineffective_union.sql'));

        $this->assertCount(1, $findings);
        $this->assertTrue($findings[0]->evidence['using_temporary_table']);
        $this->assertTrue($findings[0]->evidence['using_filesort']);
        $this->assertSame(2, $findings[0]->evidence['query_specifications_count']);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testSuggestsReviewWhenTheQueryAlreadyUsesUnionAll(): void
    {
        $findings = (new IneffectiveUnionDetector())->detect(self::union('SELECT id FROM posts UNION ALL SELECT id FROM comments'));

        $this->assertCount(1, $findings);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testIgnoresAUnionWithoutATemporaryTable(): void
    {
        $context = self::union('SELECT id FROM posts UNION SELECT id FROM comments', usingTemporaryTable: false);

        $this->assertSame([], (new IneffectiveUnionDetector())->detect($context));
    }

    public function testIgnoresAQueryWithoutAUnion(): void
    {
        $context = new QueryContext(
            sql: 'SELECT id FROM posts',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'posts', 'access_type' => 'ALL']]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        );

        $this->assertSame([], (new IneffectiveUnionDetector())->detect($context));
    }

    private static function union(string $sql, bool $usingTemporaryTable = true): QueryContext
    {
        return new QueryContext(
            sql: $sql,
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'union_result' => [
                        'table_name' => '<union1,2>',
                        'access_type' => 'ALL',
                        'using_temporary_table' => $usingTemporaryTable,
                        'using_filesort' => true,
                        'query_specifications' => [
                            ['query_block' => ['select_id' => 1]],
                            ['query_block' => ['select_id' => 2]],
                        ],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        );
    }
}

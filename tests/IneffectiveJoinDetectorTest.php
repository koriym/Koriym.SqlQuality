<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Detector\IneffectiveJoinDetector;
use PHPUnit\Framework\TestCase;

final class IneffectiveJoinDetectorTest extends TestCase
{
    public function testDetectsPlainNestedLoopJoinWithoutGroupingOperation(): void
    {
        $detector = new IneffectiveJoinDetector();

        $findings = $detector->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'access_type' => 'ALL',
                            'rows_examined_per_scan' => 5000,
                            'rows_produced_per_join' => 100,
                        ],
                    ],
                    [
                        'table' => [
                            'table_name' => 'comments',
                            'access_type' => 'ref',
                            'rows_examined_per_scan' => 1,
                            'rows_produced_per_join' => 1,
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame('posts', $findings[0]->evidence['table_name']);
        $this->assertSame('ALL', $findings[0]->evidence['access_type']);
    }

    public function testDoesNotCombineSeparateSingleTableNestedLoops(): void
    {
        $detector = new IneffectiveJoinDetector();

        $findings = $detector->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'access_type' => 'ALL',
                            'rows_examined_per_scan' => 5000,
                        ],
                    ],
                ],
                'select_list_subqueries' => [
                    [
                        'query_block' => [
                            'select_id' => 2,
                            'nested_loop' => [
                                [
                                    'table' => [
                                        'table_name' => 'users',
                                        'access_type' => 'ALL',
                                        'rows_examined_per_scan' => 5000,
                                    ],
                                ],
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
        $detector = new IneffectiveJoinDetector();

        $findings = $detector->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'access_type' => 'ref',
                            'rows_examined_per_scan' => 1,
                            'materialized_from_subquery' => [
                                'query_block' => [
                                    'select_id' => 2,
                                    'table' => [
                                        'table_name' => 'users',
                                        'access_type' => 'ALL',
                                        'rows_examined_per_scan' => 5000,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    /** @param array<string, mixed> $explain */
    private static function context(array $explain): QueryContext
    {
        return new QueryContext(sql: '', explain: $explain, explainAnalyze: null, warnings: [], schema: []);
    }
}

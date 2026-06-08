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

        $this->assertTrue($detector->detect([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'access_type' => 'ALL',
                            'rows' => 5000,
                            'rows_examined_per_scan' => 5000,
                            'rows_produced_per_join' => 100,
                        ],
                    ],
                    [
                        'table' => [
                            'table_name' => 'comments',
                            'access_type' => 'ref',
                            'rows' => 1,
                            'rows_examined_per_scan' => 1,
                            'rows_produced_per_join' => 1,
                        ],
                    ],
                ],
            ],
            'analyze_result' => [],
        ]));
    }

    public function testDoesNotCombineSeparateSingleTableNestedLoops(): void
    {
        $detector = new IneffectiveJoinDetector();

        $this->assertFalse($detector->detect([
            'query_block' => [
                'select_id' => 1,
                'nested_loop' => [
                    [
                        'table' => [
                            'table_name' => 'posts',
                            'access_type' => 'ALL',
                            'rows' => 5000,
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
                                        'rows' => 5000,
                                        'rows_examined_per_scan' => 5000,
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'analyze_result' => [],
        ]));
    }
}

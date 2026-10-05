<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function json_encode;
use function substr;

use const JSON_THROW_ON_ERROR;

final class OptimizerTraceTest extends TestCase
{
    public function testKeepsTheNodeWhosePrefixIsTheTablesBeforeItInExplainOrder(): void
    {
        $excerpt = OptimizerTrace::excerpt(self::trace(), [
            ['table_name' => 'u', 'access_type' => 'ALL'],
            ['table_name' => 'p', 'access_type' => 'ref'],
        ]);

        $this->assertNotNull($excerpt);
        $this->assertSame(['u', 'p'], [$excerpt['tables'][0]['table'], $excerpt['tables'][1]['table']]);
        $this->assertSame('scan', $excerpt['tables'][0]['considered_access_paths'][0]['access_type'] ?? null);
        $this->assertSame('ref', $excerpt['tables'][1]['considered_access_paths'][0]['access_type'] ?? null);
        $this->assertSame(['rows' => 10, 'cost' => 2.5], $excerpt['tables'][1]['range_analysis']['table_scan'] ?? null);
        $this->assertArrayNotHasKey('range_analysis', $excerpt['tables'][0]);
    }

    public function testThePrunedJoinOrderIsNotTheFinalPlan(): void
    {
        $excerpt = OptimizerTrace::excerpt(self::trace(), [
            ['table_name' => 'p', 'access_type' => 'ALL'],
            ['table_name' => 'u', 'access_type' => 'eq_ref'],
        ]);

        $this->assertNotNull($excerpt);
        $this->assertSame('pruned-p', $excerpt['tables'][0]['considered_access_paths'][0]['index'] ?? null);
        $this->assertArrayNotHasKey('considered_access_paths', $excerpt['tables'][1]);
    }

    public function testCollectsRecheckTransformationsAndConditionProcessing(): void
    {
        $excerpt = OptimizerTrace::excerpt(self::trace(), [['table_name' => 'p', 'access_type' => 'ALL']]);

        $this->assertNotNull($excerpt);
        $this->assertSame(['recheck_reason' => 'low_limit'], $excerpt['tables'][0]['rechecking_index_usage'] ?? null);
        $this->assertSame([['from' => 'IN (SELECT)', 'to' => 'semijoin', 'chosen' => true]], $excerpt['transformations']);
        $this->assertSame([['condition' => 'WHERE', 'original_condition' => '(`u`.`id` = `p`.`user_id`)']], $excerpt['condition_processing']);
    }

    public function testSkipsTablesTheTraceDoesNotName(): void
    {
        $excerpt = OptimizerTrace::excerpt(self::trace(), [['table_name' => '<derived2>', 'access_type' => 'ALL']]);

        $this->assertSame([], $excerpt['tables'] ?? null);
    }

    public function testEmptyAndTruncatedTracesYieldNull(): void
    {
        $this->assertNull(OptimizerTrace::excerpt('', []));
        $this->assertNull(OptimizerTrace::excerpt(substr(self::trace(), 0, 80), []));
    }

    private static function trace(): string
    {
        return json_encode([
            'steps' => [
                ['join_preparation' => ['steps' => [['transformation' => ['from' => 'IN (SELECT)', 'to' => 'semijoin', 'chosen' => true]]]]],
                [
                    'join_optimization' => [
                        'steps' => [
                            ['condition_processing' => ['condition' => 'WHERE', 'original_condition' => '(`u`.`id` = `p`.`user_id`)']],
                            [
                                'rows_estimation' => [
                                    ['table' => '`users` `u`', 'table_scan' => ['rows' => 100, 'cost' => 1.0]],
                                    ['table' => '`posts` `p`', 'range_analysis' => ['table_scan' => ['rows' => 10, 'cost' => 2.5]]],
                                ],
                            ],
                            [
                                'considered_execution_plans' => [
                                    [
                                        'plan_prefix' => [],
                                        'table' => '`users` `u`',
                                        'best_access_path' => ['considered_access_paths' => [['access_type' => 'scan', 'chosen' => true]]],
                                        'chosen' => true,
                                        'rest_of_plan' => [
                                            [
                                                'plan_prefix' => ['`users` `u`'],
                                                'table' => '`posts` `p`',
                                                'best_access_path' => ['considered_access_paths' => [['access_type' => 'ref', 'index' => 'idx_posts_user_id', 'chosen' => true]]],
                                                'chosen' => true,
                                            ],
                                        ],
                                    ],
                                    [
                                        'plan_prefix' => [],
                                        'table' => '`posts` `p`',
                                        'best_access_path' => ['considered_access_paths' => [['access_type' => 'scan', 'index' => 'pruned-p', 'chosen' => true]]],
                                        'pruned_by_cost' => true,
                                    ],
                                ],
                            ],
                            ['attaching_conditions_to_tables' => ['attached_conditions_computation' => [['table' => '`posts` `p`', 'rechecking_index_usage' => ['recheck_reason' => 'low_limit']]]]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}

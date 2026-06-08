<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

final class ExplainWalkerTest extends TestCase
{
    public function testCollectsTablesFromNestedLoopOrderingGroupingAndSubqueries(): void
    {
        $walker = new ExplainWalker();

        $accesses = $walker->tableAccesses([
            'query_block' => [
                'nested_loop' => [
                    ['table' => ['table_name' => 'users', 'access_type' => 'ALL']],
                ],
                'ordering_operation' => [
                    'table' => ['table_name' => 'posts', 'access_type' => 'range'],
                ],
                'grouping_operation' => [
                    'nested_loop' => [
                        ['table' => ['table_name' => 'comments', 'access_type' => 'ref']],
                    ],
                ],
                'select_list_subqueries' => [
                    [
                        'query_block' => [
                            'table' => ['table_name' => 'orders', 'access_type' => 'eq_ref'],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertSame(['users', 'posts', 'comments', 'orders'], [
            $accesses[0]['table']['table_name'],
            $accesses[1]['table']['table_name'],
            $accesses[2]['table']['table_name'],
            $accesses[3]['table']['table_name'],
        ]);
        $this->assertContains('nested_loop', $accesses[0]['path']);
        $this->assertContains('ordering_operation', $accesses[1]['path']);
        $this->assertContains('grouping_operation', $accesses[2]['path']);
        $this->assertContains('select_list_subqueries', $accesses[3]['path']);
    }

    public function testContainsFindsRecursiveKeyValuePair(): void
    {
        $walker = new ExplainWalker();

        $this->assertTrue($walker->contains([
            'query_block' => [
                'ordering_operation' => ['using_filesort' => true],
            ],
        ], 'using_filesort', true));
    }
}

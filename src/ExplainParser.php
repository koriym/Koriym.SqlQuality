<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use RuntimeException;

use function array_filter;
use function array_merge;
use function implode;
use function json_decode;
use function str_contains;

/**
 * @psalm-import-type TreeNode from Types
 * @psalm-import-type ExplainResult from Types
 */
class ExplainParser
{
    /**
     * @psalm-param string $explainJson
     *
     * @psalm-return TreeNode
     */
    public function parse(string $explainJson): TreeNode
    {
        $data = json_decode($explainJson, true);
        $queryBlock = $data['query_block'];

        if (isset($queryBlock['ordering_operation'])) {
            $orderingOp = $queryBlock['ordering_operation'];
            if (isset($orderingOp['using_temporary_table'])) {
                return $this->parseGroupAndSort($orderingOp);
            }

            return $this->parseOrderingOperation($orderingOp);
        }

        if (isset($queryBlock['grouping_operation']['nested_loop'])) {
            return $this->parseNestedLoop($queryBlock['grouping_operation']['nested_loop']);
        }

        if (isset($queryBlock['table'])) {
            $rootNode = $this->parseSingleTable($queryBlock['table']);

            // サブクエリの処理
            if (isset($queryBlock['select_list_subqueries'])) {
                $children = $rootNode->children;
                foreach ($queryBlock['select_list_subqueries'] as $subquery) {
                    $subqueryTable = $subquery['query_block']['table'];
                    $children[] = new TreeNode(
                        'Subquery (' . $subqueryTable['table_name'] . ')',
                        array_filter([
                            'access_type' => $subqueryTable['access_type'],
                            'key' => $subqueryTable['key'] ?? null,
                            'rows' => (string) $subqueryTable['rows_examined_per_scan'],
                            'filtered' => $subqueryTable['filtered'],
                            'using_index' => isset($subqueryTable['using_index']) && $subqueryTable['using_index'] ? 'true' : null,
                        ]),
                    );
                }

                $rootNode = new TreeNode(
                    $rootNode->text,
                    $rootNode->attributes,
                    $children,
                );
            }

            return $rootNode;
        }

        throw new RuntimeException('Unsupported EXPLAIN format');
    }

    /**
     * @param ExplainResult $nestedLoop
     */
    private function parseNestedLoop(array $nestedLoop): TreeNode
    {
        $children = [];

        foreach ($nestedLoop as $table) {
            $tableInfo = $table['table'];

            switch ($tableInfo['access_type']) {
                case 'index':
                    $children[] = new TreeNode(
                        'Index scan',
                        [
                            'key' => $tableInfo['key'],
                            'rows' => (string) $tableInfo['rows_examined_per_scan'],
                            'filtered' => $tableInfo['filtered'],
                        ],
                        [
                            new TreeNode(
                                'Table',
                                array_filter([
                                    'table' => $tableInfo['table_name'],
                                    'condition' => $tableInfo['attached_condition'] ?? null,
                                ]),
                            ),
                        ],
                    );
                    break;

                case 'ref':
                    $children[] = new TreeNode(
                        'Index lookup',
                        array_filter([
                            'key' => $tableInfo['key'] ?? null,
                            'rows' => isset($tableInfo['rows_examined_per_scan']) ? (string) $tableInfo['rows_examined_per_scan'] : null,
                            'filtered' => $tableInfo['filtered'] ?? null,
                        ]),
                        [
                            new TreeNode(
                                'Table',
                                ['table' => $tableInfo['table_name']],
                            ),
                        ],
                    );
                    break;

                case 'ALL':
                    $children[] = new TreeNode(
                        'Table scan',
                        ['rows' => (string) $tableInfo['rows_examined_per_scan']],
                        [
                            new TreeNode(
                                'Table',
                                array_filter([
                                    'table' => $tableInfo['table_name'],
                                    'possible_keys' => implode(', ', $tableInfo['possible_keys'] ?? []),
                                    'condition' => $tableInfo['attached_condition'] ?? null,
                                ]),
                            ),
                        ],
                    );
                    break;
            }
        }

        return new TreeNode('JOIN', [], $children);
    }

/**
 * 単一テーブルアクセスのパース
 */
    /**
     * @param ExplainResult $table
     */
    private function parseSingleTable(array $table): TreeNode
    {
        $attributes = [
            'rows' => (string) $table['rows_examined_per_scan'],
            'filtered' => $table['filtered'],
        ];

        if (isset($table['attached_condition'])) {
            $attributes['condition'] = $table['attached_condition'];
        }

        return new TreeNode(
            'Table scan',
            [],
            [
                new TreeNode(
                    'Table',
                    array_merge(
                        ['table' => $table['table_name']],
                        $attributes,
                    ),
                ),
            ],
        );
    }

/**
 * ORDER BY操作のパース
 */
    /**
     * @param ExplainResult $operation
     */
    private function parseGroupAndSort(array $operation): TreeNode
    {
        $attributes = [];
        if (isset($operation['cost_info']['sort_cost'])) {
            $attributes['sort_cost'] = $operation['cost_info']['sort_cost'];
        }

        $groupSortNode = new TreeNode(
            'Group and Sort',
            array_merge($attributes, [
                'using_temporary_table' => $operation['using_temporary_table'] ? 'true' : 'false',
                'using_filesort' => $operation['using_filesort'] ? 'true' : 'false',
            ]),
        );

        if (isset($operation['grouping_operation']['table'])) {
            $tableInfo = $operation['grouping_operation']['table'];
            $tableScan = $this->parseSingleTable($tableInfo);
            $groupSortNode = new TreeNode(
                $groupSortNode->text,
                $groupSortNode->attributes,
                [$tableScan],
            );
        }

        return $groupSortNode;
    }

    /**
     * @param ExplainResult $operation
     */
    private function parseOrderingOperation(array $operation): TreeNode
    {
        $attributes = [];
        if (isset($operation['cost_info']['sort_cost'])) {
            $attributes['sort_cost'] = $operation['cost_info']['sort_cost'];
        }

        $sortNode = new TreeNode(
            'Sort' . ($operation['using_filesort'] ? ' (using filesort)' : ''),
            $attributes,
        );

        if (isset($operation['table'])) {
            $tableInfo = $operation['table'];

            // IN条件のチェック
            if (isset($tableInfo['attached_condition']) && str_contains($tableInfo['attached_condition'], ' in (')) {
                $sortNode = new TreeNode(
                    $sortNode->text,
                    $sortNode->attributes,
                    [
                        new TreeNode(
                            'Filter with IN condition',
                            [],
                            [
                                new TreeNode(
                                    'Table scan',
                                    [],
                                    [
                                        new TreeNode(
                                            'Table',
                                            array_filter([
                                                'table' => $tableInfo['table_name'],
                                                'rows' => (string) $tableInfo['rows_examined_per_scan'],
                                                'filtered' => $tableInfo['filtered'],
                                                'condition' => $tableInfo['attached_condition'],
                                            ]),
                                        ),
                                    ],
                                ),
                            ],
                        ),
                    ],
                );
            } else {
                $tableScan = $this->parseSingleTable($operation['table']);
                $sortNode = new TreeNode(
                    $sortNode->text,
                    $sortNode->attributes,
                    [$tableScan],
                );
            }
        }

        return $sortNode;
    }
}

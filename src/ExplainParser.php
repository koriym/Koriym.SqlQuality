<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use RuntimeException;

use function array_filter;
use function array_merge;
use function json_decode;
use function sprintf;
use function str_contains;

/**
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainOperation from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type TreeNodeAttributes from Types
 */
class ExplainParser
{
    public function parse(string $explainJson): TreeNode
    {
        /** @var ExplainResult $data */
        $data = json_decode($explainJson, true);
        $queryBlock = $data['query_block'];

        // 1) ordering_operation のチェック
        if (isset($queryBlock['ordering_operation'])) {
            /** @var ExplainOperation $orderingOp */
            $orderingOp = $queryBlock['ordering_operation'];
            if (isset($orderingOp['using_temporary_table'])) {
                return $this->parseGroupAndSort($orderingOp);
            }

            return $this->parseOrderingOperation($orderingOp);
        }

        // 2) grouping_operation のチェック
        if (isset($queryBlock['grouping_operation']['nested_loop'])) {
            /** @var array<array{table: ExplainTable}> $nestedLoop */
            $nestedLoop = $queryBlock['grouping_operation']['nested_loop'];

            return $this->parseNestedLoop($nestedLoop);
        }

        // 3) 単一テーブルの場合
        if (isset($queryBlock['table'])) {
            // TableNode を一旦作る
            $tableNode = $this->parseSingleTable($queryBlock['table']);

            // 4) select_list_subqueries があれば、ここでパースして子ノードとして追加する
            if (isset($queryBlock['select_list_subqueries'])) {
                $subqueryNodes = $this->parseSelectListSubqueries($queryBlock['select_list_subqueries']);
                // もとのテーブルノードを、新しい子ノードを付与した形で再生成
                // TreeNode はイミュータブルな実装の場合があるので要注意
                $tableNode = new TreeNode(
                    $tableNode->text,
                    $tableNode->attributes,
                    // 既存の子ノード + サブクエリノードを追加
                    array_merge($tableNode->children, $subqueryNodes),
                );
            }

            return $tableNode;
        }

        throw new RuntimeException('Unsupported EXPLAIN format');
    }

    /**
     * @param array<int, mixed> $subqueries
     *
     * @return list<TreeNode>
     */
    private function parseSelectListSubqueries(array $subqueries): array
    {
        $nodes = [];
        foreach ($subqueries as $subquery) {
            // EXPLAIN の構造上、サブクエリは「query_block」キー以下に入っている想定
            if (isset($subquery['query_block']['table'])) {
                $table = $subquery['query_block']['table'];
                $nodes[] = $this->parseSingleSubquery($table);
            }
        }

        return $nodes;
    }

    /**
     * サブクエリ用のパーサ
     * "Subquery (xxx)" というタイトルでノードを作る
     */
    private function parseSingleSubquery(array $table): TreeNode
    {
        // テーブル名を元に "Subquery (table_name)" のような文字列にする
        $subqueryTitle = sprintf('Subquery (%s)', $table['table_name']);

        // 属性を組み立てる
        $attributes = array_filter([
            'access_type' => $table['access_type'] ?? null,
            'key'         => $table['key']         ?? null,
            'rows'        => isset($table['rows_examined_per_scan']) ? (string) $table['rows_examined_per_scan'] : null,
            'filtered'    => $table['filtered']    ?? null,
            'using_index' => isset($table['using_index']) && $table['using_index'] === true ? 'true' : null,
        ]);

        return new TreeNode($subqueryTitle, $attributes);
    }

    /** @param array<array{table: ExplainTable}> $nestedLoop */
    private function parseNestedLoop(array $nestedLoop): TreeNode
    {
        $children = [];
        foreach ($nestedLoop as $table) {
            /** @var ExplainTable $tableInfo */
            $tableInfo = $table['table'];
            switch ($tableInfo['access_type']) {
                case 'index':
                    /** @var TreeNodeAttributes $attributes */
                    $attributes = [
                        'key' => $tableInfo['key'] ?? null,
                        'rows' => (string) $tableInfo['rows_examined_per_scan'],
                        'filtered' => (string) $tableInfo['filtered'],
                    ];
                    /** @var TreeNodeAttributes $tableAttributes */
                    $tableAttributes = array_filter([
                        'table' => $tableInfo['table_name'],
                        'condition' => $tableInfo['attached_condition'] ?? null,
                    ]);
                    $children[] = new TreeNode(
                        'Index scan',
                        $attributes,
                        [
                            new TreeNode('Table', $tableAttributes),
                        ],
                    );
                    break;

                case 'ref':
                    /** @var TreeNodeAttributes $attributes */
                    $attributes = array_filter([
                        'key' => $tableInfo['key'] ?? null,
                        'rows' => isset($tableInfo['rows_examined_per_scan']) ? (string) $tableInfo['rows_examined_per_scan'] : null,
                        'filtered' => isset($tableInfo['filtered']) ? (string) $tableInfo['filtered'] : null,
                    ]);
                    /** @var TreeNodeAttributes $tableAttributes */
                    $tableAttributes = ['table' => $tableInfo['table_name']];
                    $children[] = new TreeNode(
                        'Index lookup',
                        $attributes,
                        [new TreeNode('Table', $tableAttributes)],
                    );
                    break;

                case 'ALL':
                    /** @var TreeNodeAttributes $attributes */
                    $attributes = ['rows' => (string) $tableInfo['rows_examined_per_scan']];
                    /** @var TreeNodeAttributes $tableAttributes */
                    $tableAttributes = array_filter([
                        'table' => $tableInfo['table_name'],
                        'possible_keys' => $tableInfo['possible_keys'] ?? null,
                        'condition' => $tableInfo['attached_condition'] ?? null,
                    ]);
                    $children[] = new TreeNode(
                        'Table scan',
                        $attributes,
                        [new TreeNode('Table', $tableAttributes)],
                    );
                    break;
            }
        }

        return new TreeNode('JOIN', [], $children);
    }

    /** @param ExplainTable $table */
    private function parseSingleTable(array $table): TreeNode
    {
        /** @var TreeNodeAttributes $attributes */
        $attributes = [
            'rows' => (string) $table['rows_examined_per_scan'],
            'filtered' => (string) $table['filtered'],
        ];

        if (isset($table['attached_condition'])) {
            $attributes['condition'] = $table['attached_condition'];
        }

        /** @var TreeNodeAttributes $tableAttributes */
        $tableAttributes = array_merge(
            ['table' => $table['table_name']],
            $attributes,
        );

        return new TreeNode(
            'Table scan',
            [],
            [new TreeNode('Table', $tableAttributes)],
        );
    }

    /** @param ExplainOperation $operation */
    private function parseGroupAndSort(array $operation): TreeNode
    {
        /** @var TreeNodeAttributes $attributes */
        $attributes = [];
        if (isset($operation['cost_info']['sort_cost'])) {
            $attributes['sort_cost'] = (string) $operation['cost_info']['sort_cost'];
        }

        $groupSortNode = new TreeNode(
            'Group and Sort',
            array_merge($attributes, [
                'using_temporary_table' => isset($operation['using_temporary_table']) && $operation['using_temporary_table'] ? 'true' : 'false',
                'using_filesort' => isset($operation['using_filesort']) && $operation['using_filesort'] ? 'true' : 'false',
            ]),
        );

        if (isset($operation['table'])) {
            $tableScan = $this->parseSingleTable($operation['table']);
            $groupSortNode = new TreeNode(
                $groupSortNode->text,
                $groupSortNode->attributes,
                [$tableScan],
            );
        }

        return $groupSortNode;
    }

    /** @param ExplainOperation $operation */
    private function parseOrderingOperation(array $operation): TreeNode
    {
        /** @var TreeNodeAttributes $attributes */
        $attributes = [];
        if (isset($operation['cost_info']['sort_cost'])) {
            $attributes['sort_cost'] = (string) $operation['cost_info']['sort_cost'];
        }

        $sortNode = new TreeNode(
            'Sort' . (isset($operation['using_filesort']) && $operation['using_filesort'] ? ' (using filesort)' : ''),
            $attributes,
        );

        if (isset($operation['table'])) {
            if (
                isset($operation['table']['attached_condition']) &&
                str_contains($operation['table']['attached_condition'], ' in (')
            ) {
                /** @var TreeNodeAttributes $tableAttributes */
                $tableAttributes = array_filter([
                    'table' => $operation['table']['table_name'],
                    'rows' => (string) $operation['table']['rows_examined_per_scan'],
                    'filtered' => (string) $operation['table']['filtered'],
                    'condition' => $operation['table']['attached_condition'],
                ]);

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
                                    [new TreeNode('Table', $tableAttributes)],
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

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use RuntimeException;

use function array_filter;
use function array_merge;
use function implode;
use function is_array;
use function json_decode;
use function print_r;
use function sprintf;
use function str_contains;

use const JSON_THROW_ON_ERROR;

/**
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainOperation from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type TreeNodeAttributes from Types
 * @psalm-import-type QueryBlock from Types
 */
class ExplainParser
{
    public function parse(string $explainJson): TreeNode
    {
        /** @var array{query_block: QueryBlock} $data */
        $data = json_decode($explainJson, true, 512, JSON_THROW_ON_ERROR);

        return $this->parseQueryBlock($data['query_block']);
    }

    /** @param QueryBlock $queryBlock */
    public function parseQueryBlock(array $queryBlock): TreeNode
    {
        if (isset($queryBlock['message'])) {
            // 例: "no matching row in const table"
            $msg = $queryBlock['message'];

            return new TreeNode(
                'Message',
                ['info' => $msg]
            );
        }

        // union_result
        if (isset($queryBlock['union_result'])) {
            return $this->parseUnionResult($queryBlock['union_result']);
        }

        // ordering_operation
        if (isset($queryBlock['ordering_operation'])) {
            /** @var ExplainOperation $orderingOp */
            $orderingOp = $queryBlock['ordering_operation'];
            if (isset($orderingOp['using_temporary_table'])) {
                return $this->parseGroupAndSort($orderingOp);
            }

            return $this->parseOrderingOperation($orderingOp);
        }

        // grouping_operation
        if (isset($queryBlock['grouping_operation']['nested_loop'])) {
            /** @var array<array{table: ExplainTable}> $nestedLoop */
            $nestedLoop = $queryBlock['grouping_operation']['nested_loop'];

            return $this->parseNestedLoop($nestedLoop);
        }

        // 3) nested_loop が query_block 直下にある場合のチェック
        if (isset($queryBlock['nested_loop'])) {
            /** @var array<array{table: ExplainTable}> $nestedLoop */
            $nestedLoop = $queryBlock['nested_loop'];

            return $this->parseNestedLoop($nestedLoop);
        }

        // table
        if (isset($queryBlock['table'])) {
            // TableNode を一旦作る
            $tableNode = $this->parseSingleTable($queryBlock['table']);

            // select_list_subqueries があれば、ここでパースして子ノードとして追加する
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

        throw new RuntimeException('Unsupported EXPLAIN query_block format:' . print_r($queryBlock, true));
    }

    private function parseUnionResult(array $unionResult): TreeNode
    {
        // UNION ノードの属性を詰める（あれば）
        $attributes = [];
        if (isset($unionResult['table_name'])) {
            $attributes['table'] = $unionResult['table_name']; // "<union1,2>" など
        }

        if (isset($unionResult['using_temporary_table']) && $unionResult['using_temporary_table']) {
            $attributes['using_temporary_table'] = 'true';
        }

        if (isset($unionResult['access_type'])) {
            $attributes['access_type'] = $unionResult['access_type'];
        }

        // query_specifications があれば、それぞれ parseQueryBlock() で再帰的にパース
        $children = [];
        if (isset($unionResult['query_specifications']) && is_array($unionResult['query_specifications'])) {
            foreach ($unionResult['query_specifications'] as $spec) {
                // spec: { "dependent":false, "cacheable":true, "query_block": {...} }
                if (isset($spec['query_block']) && is_array($spec['query_block'])) {
                    // 再帰的に、各 query_block をパース
                    $childNode = $this->parseQueryBlock($spec['query_block']);
                    $children[] = $childNode;
                }
            }
        }

        return new TreeNode('UNION', $attributes, $children);
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
                    /** @psalm-suppress PossiblyInvalidArgument */
                    $tableAttributes = array_filter([
                        'table' => $tableInfo['table_name'],
                        'possible_keys' => implode(', ', $tableInfo['possible_keys'] ?? []),
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

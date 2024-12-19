<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function preg_match_all;
use function preg_replace;
use function sprintf;
use function str_contains;
use function var_dump;

class ExplainAnalyzer
{
    private array $issues = [];
    private string $sqlFile = '';

    public function setSqlFile(string $sqlFile): void
    {
        $this->sqlFile = $sqlFile;
    }

    public function analyze(array $explainResult): array
    {
        // var_dump($explainResult);
        if (! isset($explainResult['query_block'])) {
            return [];
        }

        $this->issues = [];
        $queryBlock = $explainResult['query_block'];

        // 基本的なテーブルアクセスの分析
        if (isset($queryBlock['table'])) {
            $this->analyzeTable($queryBlock['table']);
        }

        // ソート操作の分析
        if (isset($queryBlock['ordering_operation'])) {
            $this->analyzeOrdering($queryBlock['ordering_operation']);
        }

        // GROUP BY操作の分析
        if (isset($queryBlock['grouping_operation'])) {
            $this->analyzeGrouping($queryBlock['grouping_operation']);
        }

        // JOINの分析
        if (isset($queryBlock['nested_loop'])) {
            foreach ($queryBlock['nested_loop'] as $join) {
                if (isset($join['table'])) {
                    $this->analyzeTable($join['table']);
                }
            }
        }

        foreach ($this->issues as &$issue) {
            $issue['sql_file'] = $this->sqlFile;
        }

        return $this->issues;
    }

    private function analyzeTable(array $table): void
    {
        $tableName = $table['table_name'] ?? 'unknown';

        // フルテーブルスキャンの検出
        if (isset($table['access_type']) && $table['access_type'] === 'ALL') {
            $this->issues[] = [
                'type' => 'full_table_scan',
                'table' => $tableName,
                'description' => 'Full table scan detected. Consider adding an index.',
            ];
        }

        // インデックスが使えるのに使っていない場合
        if (isset($table['possible_keys']) && (! isset($table['key']) || empty($table['key']))) {
            $this->issues[] = [
                'type' => 'unused_available_index',
                'table' => $tableName,
                'description' => 'Index available but not used.',
            ];
        }

        // 条件句の分析
        if (isset($table['attached_condition'])) {
            $condition = $table['attached_condition'];

            // 関数使用の検出
            if (str_contains($condition, 'DATE(') || str_contains($condition, 'cast(')) {
                $this->issues[] = [
                    'type' => 'function_on_indexed_column',
                    'table' => $tableName,
                    'description' => 'Function used on column prevents index usage',
                ];
            }

            // LIKEパターンの検出（複数のLIKE条件も個別に検出）
            $matches = [];
            preg_match_all("/`[^`]+` like '%[^']*%'/i", $condition, $matches);
            foreach ($matches[0] as $match) {
                $this->issues[] = [
                    'type' => 'leading_wildcard_like',
                    'table' => $tableName,
                    'description' => sprintf(
                        'Leading wildcard in LIKE on %s prevents index usage',
                        preg_replace('/`([^`]+)`.*$/', '$1', $match),
                    ),
                ];
            }
        }
    }

    private function analyzeOrdering(array $ordering): void
    {
        if (isset($ordering['using_filesort']) && $ordering['using_filesort'] === true) {
            $tableName = $ordering['table']['table_name'] ?? 'unknown';
            $this->issues[] = [
                'type' => 'filesort',
                'table' => $tableName,
                'description' => 'Filesort detected in ORDER BY. Consider adding an index for sorting columns.',
            ];
        }

        if (isset($ordering['table'])) {
            $this->analyzeTable($ordering['table']);
        }
    }

    private function analyzeGrouping(array $grouping): void
    {
        if (isset($grouping['using_temporary_table']) && $grouping['using_temporary_table'] === true) {
            $tableName = $grouping['nested_loop'][0]['table']['table_name'] ?? 'unknown';
            $this->issues[] = [
                'type' => 'temporary_table',
                'table' => $tableName,
                'description' => 'Temporary table used for grouping. Consider adding an index for GROUP BY clause',
            ];
        }

        if (isset($grouping['nested_loop'])) {
            foreach ($grouping['nested_loop'] as $join) {
                if (isset($join['table'])) {
                    $this->analyzeTable($join['table']);
                }
            }
        }
    }

    /** @param array<int, array<string, string>> $issues */
    public function getFormattedIssues(array $issues): string
    {
        if (empty($issues)) {
            return sprintf("No issues found in SQL file: %s\n", $this->sqlFile);
        }

        $output = sprintf("Issues found in SQL file: %s\n", $this->sqlFile);
        foreach ($issues as $issue) {
            $output .= sprintf(
                "- %s: %s%s\n",
                $issue['type'],
                $issue['description'],
                isset($issue['table']) ? sprintf(' (Table: %s)', $issue['table']) : '',
            );
        }

        return $output;
    }
}

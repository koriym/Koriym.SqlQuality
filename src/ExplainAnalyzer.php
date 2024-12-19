<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_filter;
use function array_unique;
use function implode;
use function preg_match_all;
use function sprintf;
use function str_contains;
use function usort;

class ExplainAnalyzer
{
    private array $issues = [];
    private string $sqlFile = '';

    /**
     * 問題の重要度レベル
     */
    private const PRIORITY_ROOT = 'root';      // 根本的な問題
    private const PRIORITY_DERIVED = 'derived'; // 派生的な問題

    public function setSqlFile(string $sqlFile): void
    {
        $this->sqlFile = $sqlFile;
    }

    public function analyze(array $explainResult): array
    {
        if (! isset($explainResult['query_block'])) {
            return [];
        }

        $this->issues = [];
        $queryBlock = $explainResult['query_block'];

        if (isset($queryBlock['table'])) {
            $this->analyzeTable($queryBlock['table']);
        }

        if (isset($queryBlock['ordering_operation'])) {
            $this->analyzeOrdering($queryBlock['ordering_operation']);
        }

        if (isset($queryBlock['grouping_operation'])) {
            $this->analyzeGrouping($queryBlock['grouping_operation']);
        }

        foreach ($this->issues as &$issue) {
            $issue['sql_file'] = $this->sqlFile;
        }

        // 優先度順にソート
        usort($this->issues, static function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        return $this->issues;
    }

    private function analyzeTable(array $table): void
    {
        $tableName = $table['table_name'] ?? 'unknown';

        // 根本的な問題: インデックスが使えるのに使っていない
        if (isset($table['possible_keys']) && (! isset($table['key']) || empty($table['key']))) {
            $this->issues[] = [
                'type' => 'unused_available_index',
                'table' => $tableName,
                'description' => 'Available indexes are not being used. This is causing unnecessary table scans.',
                'priority' => self::PRIORITY_ROOT,
            ];
        }
        // その他のフルテーブルスキャンはインデックスがない場合
        elseif (isset($table['access_type']) && $table['access_type'] === 'ALL') {
            $this->issues[] = [
                'type' => 'full_table_scan',
                'table' => $tableName,
                'description' => 'Full table scan detected. Consider adding an appropriate index.',
                'priority' => self::PRIORITY_ROOT,
            ];
        }

        // 根本的な問題: JOINの問題
        if (isset($table['using_join_buffer'])) {
            $this->issues[] = [
                'type' => 'inefficient_join',
                'table' => $tableName,
                'description' => sprintf(
                    'Inefficient JOIN operation using %s. Add an index for JOIN conditions.',
                    $table['using_join_buffer'],
                ),
                'priority' => self::PRIORITY_ROOT,
            ];
        }

        // 根本的な問題: インデックスを使えない条件
        if (isset($table['attached_condition'])) {
            $condition = $table['attached_condition'];

            // 関数使用
            if (str_contains($condition, 'DATE(') || str_contains($condition, 'cast(')) {
                $this->issues[] = [
                    'type' => 'function_on_column',
                    'table' => $tableName,
                    'description' => 'Function call in WHERE clause prevents index usage. Rewrite the condition.',
                    'priority' => self::PRIORITY_ROOT,
                ];
            }

            // LIKE with wildcards
            $matches = [];
            preg_match_all("/`([^`]+)` like '%[^']*%'/i", $condition, $matches);
            if (! empty($matches[1])) {
                $columns = implode(', ', array_unique($matches[1]));
                $this->issues[] = [
                    'type' => 'inefficient_like',
                    'table' => $tableName,
                    'description' => sprintf(
                        'Leading wildcard in LIKE on column(s) %s prevents index usage. Consider using full-text search.',
                        $columns,
                    ),
                    'priority' => self::PRIORITY_ROOT,
                ];
            }
        }

        // 派生的な問題: 高いフィルタ率（他の問題の結果として）
        if (
            isset($table['filtered'])
            && (float) $table['filtered'] > 90
            && isset($table['rows_examined_per_scan'])
            && $table['rows_examined_per_scan'] > 100
        ) {
            $this->issues[] = [
                'type' => 'low_selectivity',
                'table' => $tableName,
                'description' => sprintf(
                    'Query examines %.1f%% of scanned rows. Conditions are not selective enough.',
                    (float) $table['filtered'],
                ),
                'priority' => self::PRIORITY_DERIVED,
            ];
        }
    }

    private function analyzeOrdering(array $ordering): void
    {
        if (isset($ordering['using_filesort']) && $ordering['using_filesort'] === true) {
            $tableName = $ordering['table']['table_name'] ?? 'unknown';
            $this->issues[] = [
                'type' => 'filesort_required',
                'table' => $tableName,
                'description' => 'Extra sorting required for ORDER BY. Add an index that matches the ordering.',
                'priority' => self::PRIORITY_ROOT,
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
                'type' => 'temp_table_required',
                'table' => $tableName,
                'description' => 'Temporary table required for GROUP BY. Add an index that matches the grouping.',
                'priority' => self::PRIORITY_ROOT,
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

    public function getFormattedIssues(array $issues): string
    {
        if (empty($issues)) {
            return sprintf("No issues found in SQL file: %s\n", $this->sqlFile);
        }

        $output = sprintf("Issues found in SQL file: %s\n", $this->sqlFile);

        // 根本的な問題を先に表示
        if ($rootIssues = array_filter($issues, static fn ($i) => $i['priority'] === self::PRIORITY_ROOT)) {
            $output .= "\nPrimary issues:\n";
            foreach ($rootIssues as $issue) {
                $output .= sprintf(
                    "- %s: %s%s\n",
                    $issue['type'],
                    $issue['description'],
                    isset($issue['table']) ? sprintf(' (Table: %s)', $issue['table']) : '',
                );
            }
        }

        // 派生的な問題は後で表示
        if ($derivedIssues = array_filter($issues, static fn ($i) => $i['priority'] === self::PRIORITY_DERIVED)) {
            $output .= "\nSecondary issues:\n";
            foreach ($derivedIssues as $issue) {
                $output .= sprintf(
                    "- %s: %s%s\n",
                    $issue['type'],
                    $issue['description'],
                    isset($issue['table']) ? sprintf(' (Table: %s)', $issue['table']) : '',
                );
            }
        }

        return $output;
    }
}

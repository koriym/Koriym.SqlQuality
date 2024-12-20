<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function sprintf;
use function str_repeat;
use function strlen;

class QueryOptimizationSuggester
{
    /**
     * @param array<array<string, mixed>> $issues
     *
     * @return array<string, array<string, mixed>>
     */
    public function suggestOptimizations(array $issues): array
    {
        $suggestions = [];

        foreach ($issues as $issue) {
            $tableName = $issue['table'] ?? 'unknown';

            $suggestion = match ($issue['type']) {
                'unused_available_index' => $this->suggestIndexUsage($issue),
                'full_table_scan' => $this->suggestCreateIndex($issue),
                'inefficient_join' => $this->suggestJoinOptimization($issue),
                'function_on_column' => $this->suggestFunctionOptimization($issue),
                'inefficient_like' => $this->suggestLikeOptimization($issue),
                'low_selectivity' => $this->suggestSelectivityOptimization($issue),
                'filesort_required' => $this->suggestOrderByOptimization($issue),
                'temp_table_required' => $this->suggestGroupByOptimization($issue),
                default => null
            };

            if ($suggestion !== null) {
                $suggestions[$tableName][] = $suggestion;
            }
        }

        return $suggestions;
    }

    private function suggestCreateIndex(array $issue): array
    {
        return [
            'type' => 'create_index',
            'description' => sprintf(
                'Consider creating an index on the commonly queried columns of table `%s`.',
                $issue['table'],
            ),
            'example_sql' => sprintf(
                "-- Add appropriate column names based on your query\n" .
                'CREATE INDEX idx_%s_column ON %s (column_name);',
                $issue['table'],
                $issue['table'],
            ),
            'benefits' => [
                'Reduces full table scan overhead',
                'Improves query response time',
                'Reduces server load',
            ],
        ];
    }

    private function suggestIndexUsage(array $issue): array
    {
        return [
            'type' => 'force_index',
            'description' => sprintf(
                'The table `%s` has available indexes but they are not being used. ' .
                'Consider forcing an index or restructuring the query.',
                $issue['table'],
            ),
            'example_sql' => sprintf(
                "-- Example of forcing an index\n" .
                'SELECT * FROM %s FORCE INDEX (index_name) WHERE ...',
                $issue['table'],
            ),
            'considerations' => [
                'Verify that forcing the index actually improves performance',
                'Consider if query restructuring might be better than forcing an index',
            ],
        ];
    }

    private function suggestJoinOptimization(array $issue): array
    {
        return [
            'type' => 'join_optimization',
            'description' => sprintf(
                'Optimize the JOIN operation on table `%s` by adding appropriate indexes ' .
                'on the join columns.',
                $issue['table'],
            ),
            'example_sql' => sprintf(
                "-- Add index on the JOIN columns\n" .
                'CREATE INDEX idx_%s_join ON %s (join_column);',
                $issue['table'],
                $issue['table'],
            ),
            'recommendations' => [
                'Ensure indexes exist on both sides of the JOIN',
                'Consider denormalization for frequently joined tables',
                'Review JOIN conditions for potential simplification',
            ],
        ];
    }

    private function suggestFunctionOptimization(array $issue): array
    {
        return [
            'type' => 'function_optimization',
            'description' => sprintf(
                'Functions in WHERE clause on table `%s` prevent index usage. ' .
                'Consider restructuring the conditions.',
                $issue['table'],
            ),
            'alternatives' => [
                'DATE()' => [
                    'description' => 'Instead of DATE(column), use direct comparison',
                    'example' => "column >= '2024-01-01 00:00:00' AND column < '2024-01-02 00:00:00'",
                ],
                'CAST()' => [
                    'description' => 'Store data in the correct type to avoid casting',
                    'example' => 'Consider changing column type or adding computed column',
                ],
            ],
        ];
    }

    private function suggestLikeOptimization(array $issue): array
    {
        return [
            'type' => 'like_optimization',
            'description' => sprintf(
                'Leading wildcard LIKE on table `%s` prevents efficient index usage.',
                $issue['table'],
            ),
            'alternatives' => [
                [
                    'solution' => 'Use FULLTEXT index',
                    'example_sql' => sprintf(
                        "ALTER TABLE %s ADD FULLTEXT INDEX ft_idx (column_name);\n" .
                        "-- Then use:\n" .
                        "SELECT * FROM %s WHERE MATCH(column_name) AGAINST('search_term');",
                        $issue['table'],
                        $issue['table'],
                    ),
                ],
                [
                    'solution' => 'Consider using Elasticsearch or similar for text search',
                    'benefits' => [
                        'Better performance for text search',
                        'More advanced search capabilities',
                        'Reduced database load',
                    ],
                ],
            ],
        ];
    }

    private function suggestSelectivityOptimization(array $issue): array
    {
        return [
            'type' => 'selectivity_optimization',
            'description' => sprintf(
                'Low selectivity on table `%s` indicates inefficient filtering. ' .
                'Consider improving WHERE conditions or indexes.',
                $issue['table'],
            ),
            'recommendations' => [
                'Review and possibly combine indexes',
                'Add more specific conditions to reduce result set',
                'Consider partitioning for large tables',
            ],
            'example_sql' => sprintf(
                "-- Example of compound index for better selectivity\n" .
                'CREATE INDEX idx_%s_compound ON %s (high_selectivity_col, low_selectivity_col);',
                $issue['table'],
                $issue['table'],
            ),
        ];
    }

    private function suggestOrderByOptimization(array $issue): array
    {
        return [
            'type' => 'order_by_optimization',
            'description' => sprintf(
                'Filesort detected on table `%s`. Create an index matching the ORDER BY clause.',
                $issue['table'],
            ),
            'example_sql' => sprintf(
                "-- Add index matching the ORDER BY columns in the same order\n" .
                'CREATE INDEX idx_%s_order ON %s (order_col1 [ASC|DESC], order_col2 [ASC|DESC]);',
                $issue['table'],
                $issue['table'],
            ),
            'notes' => [
                'Index column order must match ORDER BY clause exactly',
                'Sort direction (ASC/DESC) must also match',
                'Consider covering index to avoid additional lookups',
            ],
        ];
    }

    private function suggestGroupByOptimization(array $issue): array
    {
        return [
            'type' => 'group_by_optimization',
            'description' => sprintf(
                'Temporary table created for GROUP BY on table `%s`. ' .
                'Consider adding an appropriate index.',
                $issue['table'],
            ),
            'example_sql' => sprintf(
                "-- Add index matching the GROUP BY columns\n" .
                "CREATE INDEX idx_%s_group ON %s (group_col1, group_col2);\n" .
                "-- Consider including aggregated columns for covering index\n" .
                'CREATE INDEX idx_%s_group_covering ON %s (group_col1, group_col2, agg_col);',
                $issue['table'],
                $issue['table'],
                $issue['table'],
                $issue['table'],
            ),
            'considerations' => [
                'Include GROUP BY columns in the same order as in query',
                'Consider including aggregated columns in index',
                'Review if grouping can be done differently',
            ],
        ];
    }

    /** @param array<string, array<string, mixed>> $suggestions */
    public function formatSuggestions(array $suggestions): string
    {
        $output = "Optimization Suggestions:\n\n";

        foreach ($suggestions as $table => $tableSuggestions) {
            $output .= sprintf("Table: %s\n", $table);
            $output .= str_repeat('-', strlen($table) + 7) . "\n";

            foreach ($tableSuggestions as $suggestion) {
                $output .= sprintf("* %s\n", $suggestion['description']);

                if (isset($suggestion['example_sql'])) {
                    $output .= "\nExample SQL:\n```sql\n" . $suggestion['example_sql'] . "\n```\n";
                }

                if (isset($suggestion['recommendations'])) {
                    $output .= "\nRecommendations:\n";
                    foreach ($suggestion['recommendations'] as $rec) {
                        $output .= sprintf("- %s\n", $rec);
                    }
                }

                if (isset($suggestion['considerations'])) {
                    $output .= "\nConsiderations:\n";
                    foreach ($suggestion['considerations'] as $con) {
                        $output .= sprintf("- %s\n", $con);
                    }
                }

                $output .= "\n";
            }
        }

        return $output;
    }
}

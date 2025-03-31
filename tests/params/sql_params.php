<?php

declare(strict_types=1);

return [
    '1_full_table_scan.sql' => ['min_views' => 1000],
    '2_filesort.sql' => [
        'status' => 'published',
        'limit' => 10,
    ],
    '3_function_on_indexed_column.sql' => ['target_date' => '2024-01-01'],
    '4_no_index_on_join.sql' => ['status' => 'published'],
    '5_multiple_wildcard_like.sql' => ['search_word' => '%keyword%'],
    '6_implicit_type_conversion.sql' => ['reference_code' => 12345],
    '7_temporary_table_grouping.sql' => [],
    '8_suboptimal_or_condition.sql' => ['user_id' => 1],
    '9_inefficient_in_query.sql' => [
        'status1' => 'draft',
        'status2' => 'published',
        'status3' => 'archived',
        'status4' => 'deleted',
        'status5' => 'pending',
    ],
    '10_redundant_join.sql' => ['status' => 'active'],
    '11_nested_loop.sql' => ['email' => 'example@example.com'],
    '12_select1.sql' => [],
    '13_invalid.sql' => [],
    '14_not_found.sql' => [],
    '15_listed_parameters.sql' => ['listed_params' => ['User 1', 'User 10']],
    '16_ineffective_range_scan.sql' => [
        'min_amount' => 1,
        'max_amount' => 1000,
    ],
    '17_empty_list.sql' => ['empty_list' => []],
    '18_select_distinct.sql' => ['user_ids' => [371, 963], 'comment_ids' => [1, 2, 555, 999]],
    '19_unnecessary_distinct.sql' => ['user_id' => 1],
    '20_multi_table_update.sql' => [],
    '21_low_cardinality_index.sql' => ['status' => 'active'],
    '24_grouping_operation.sql' => [
        'post_ids' => [375, 376, 377, 388, 389, 390],
        'target_count' => 2,
    ],
    '25_pass_the_with_clause.sql' => [],
];

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
    '7_temporary_table_grouping.sql' => [],  // バインド値不要
    '8_suboptimal_or_condition.sql' => [
        'user_id' => 1,
    ],
    '9_inefficient_in_query.sql' => [
        'status1' => 'draft',
        'status2' => 'published',
        'status3' => 'archived',
        'status4' => 'deleted',
        'status5' => 'pending',
    ],
    '10_redundant_join.sql' => [
        'status' => 'active',
    ]
];

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
];

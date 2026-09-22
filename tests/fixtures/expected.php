<?php

declare(strict_types=1);

return [
    '10_redundant_join.sql' => ['LowCardinalityIndex'],
    '11_nested_loop.sql' => ['FullTableScan', 'IneffectiveJoin', 'IneffectiveRangeScan'],
    '12_select1.sql' => [],
    '14_correlated_subquery.sql' => ['FullTableScan'],
    '15_listed_parameters.sql' => ['FullTableScan', 'IneffectiveRangeScan'],
    '16_ineffective_range_scan.sql' => ['FullTableScan', 'IneffectiveRangeScan', 'IneffectiveSort'],
    '18_select_distinct.sql' => ['UnnecessaryDistinct'],
    '19_unnecessary_distinct.sql' => ['UnnecessaryDistinct'],
    '1_full_table_scan.sql' => ['FullTableScan'],
    '20_multi_table_update.sql' => ['FullTableScan', 'IneffectiveJoin', 'MultiTableUpdate'],
    '21_low_cardinality_index.sql' => ['LowCardinalityIndex'],
    '22_excessive_derived_tables.sql' => ['IneffectiveJoin', 'LowCardinalityIndex'],
    '23_ineffective_union.sql' => ['IneffectiveJoin', 'IneffectiveUnion', 'LowCardinalityIndex', 'TemporaryTableGrouping'],
    '24_grouping_operation.sql' => [],
    '25_pass_the_with_clause.sql' => ['IneffectiveJoin', 'LowCardinalityIndex'],
    '26_join_without_index.sql' => ['FullTableScan', 'IneffectiveJoin', 'IneffectiveRangeScan'],
    '27_range_scan_low_filter.sql' => ['IneffectiveLikePattern', 'IneffectiveRangeScan'],
    '2_filesort.sql' => ['IneffectiveSort', 'LowCardinalityIndex'],
    '3_function_on_indexed_column.sql' => ['FullTableScan', 'FunctionInvalidatesIndex'],
    '4_no_index_on_join.sql' => ['IneffectiveJoin', 'LowCardinalityIndex', 'TemporaryTableGrouping'],
    '5_multiple_wildcard_like.sql' => ['FullTableScan', 'IneffectiveLikePattern'],
    '6_implicit_type_conversion.sql' => ['FullTableScan', 'ImplicitTypeConversion', 'IneffectiveRangeScan'],
    '7_temporary_table_grouping.sql' => ['TemporaryTableGrouping'],
    '8_suboptimal_or_condition.sql' => ['FullTableScan', 'ImplicitTypeConversion'],
    '9_inefficient_in_query.sql' => ['FullTableScan', 'IneffectiveRangeScan', 'IneffectiveSort'],
];

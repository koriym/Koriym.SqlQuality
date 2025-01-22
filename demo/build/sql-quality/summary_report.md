# SQL Analysis Summary

## Query Analysis List
| SQL File | Cost | Level | Issues | Report |
|----------|------|-------|---------|---------|
| 1\_full\_table\_scan.sql | 523.20 | Medium (μ ± σ) | FullTableScan | [Details](1_full_table_scan.md) |
| 2\_filesort.sql | 523.20 | Medium (μ ± σ) | FullTableScan, IneffectiveSort | [Details](2_filesort.md) |
| 3\_function\_on\_indexed\_column.sql | 523.20 | Medium (μ ± σ) | FullTableScan | [Details](3_function_on_indexed_column.md) |
| 4\_no\_index\_on\_join.sql | 1124.71 | Medium (μ ± σ) | - | [Details](4_no_index_on_join.md) |
| 5\_multiple\_wildcard\_like.sql | 523.20 | Medium (μ ± σ) | FullTableScan | [Details](5_multiple_wildcard_like.md) |
| 6\_implicit\_type\_conversion.sql | 210.00 | Medium (μ ± σ) | FullTableScan | [Details](6_implicit_type_conversion.md) |
| 7\_temporary\_table\_grouping.sql | 210.00 | Medium (μ ± σ) | IneffectiveSort, TemporaryTableGrouping | [Details](7_temporary_table_grouping.md) |
| 8\_suboptimal\_or\_condition.sql | 523.20 | Medium (μ ± σ) | FullTableScan | [Details](8_suboptimal_or_condition.md) |
| 9\_inefficient\_in\_query.sql | 2974.20 | Very High (> μ + 2σ) | FullTableScan, IneffectiveSort | [Details](9_inefficient_in_query.md) |
| 10\_redundant\_join.sql | 107.00 | Medium (μ ± σ) | FullTableScan | [Details](10_redundant_join.md) |
| 11\_nested\_loop.sql | 648.93 | Medium (μ ± σ) | FullTableScan | [Details](11_nested_loop.md) |
| 12\_select1.sql | 0.00 | Medium (μ ± σ) | - | [Details](12_select1.md) |

## Project Statistics
- Total SQLs analyzed: 12
- Average query cost: 657.57
- Standard deviation: 753.41
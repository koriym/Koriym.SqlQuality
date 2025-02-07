# SQL Analysis Summary

## Query Analysis List
| SQL File | Cost | Exec Time (ms) | Level | Issues | Report |
|----------|------|----------------|-------|--------|--------|
| 1\_full\_table\_scan.sql | 503.75 | 7.60 | Medium (μ ± σ) | FullTableScan | [Details](1_full_table_scan.md) |
| 2\_filesort.sql | 503.75 | 5.98 | Medium (μ ± σ) | FullTableScan, IneffectiveSort | [Details](2_filesort.md) |
| 3\_function\_on\_indexed\_column.sql | 503.75 | 3.02 | Medium (μ ± σ) | FullTableScan | [Details](3_function_on_indexed_column.md) |
| 4\_no\_index\_on\_join.sql | 742.64 | 21.26 | Medium (μ ± σ) | - | [Details](4_no_index_on_join.md) |
| 5\_multiple\_wildcard\_like.sql | 503.75 | 5.77 | Medium (μ ± σ) | FullTableScan | [Details](5_multiple_wildcard_like.md) |
| 6\_implicit\_type\_conversion.sql | 202.50 | 1.65 | Medium (μ ± σ) | FullTableScan | [Details](6_implicit_type_conversion.md) |
| 7\_temporary\_table\_grouping.sql | 202.50 | 1.24 | Medium (μ ± σ) | IneffectiveSort, TemporaryTableGrouping | [Details](7_temporary_table_grouping.md) |
| 8\_suboptimal\_or\_condition.sql | 503.75 | 8.64 | Medium (μ ± σ) | FullTableScan | [Details](8_suboptimal_or_condition.md) |
| 9\_inefficient\_in\_query.sql | 2981.25 | 14.75 | Very High (> μ + 2σ) | FullTableScan, IneffectiveSort | [Details](9_inefficient_in_query.md) |
| 10\_redundant\_join.sql | 101.75 | 7.95 | Medium (μ ± σ) | FullTableScan | [Details](10_redundant_join.md) |
| 11\_nested\_loop.sql | 276.05 | 0.51 | Medium (μ ± σ) | FullTableScan | [Details](11_nested_loop.md) |
| 12\_select1.sql | 0.00 | 0.10 | Medium (μ ± σ) | - | [Details](12_select1.md) |
| 15\_listed\_parameters.sql | 101.75 | 0.57 | Medium (μ ± σ) | FullTableScan | [Details](15_listed_parameters.md) |
| 16\_listed\_num\_parameters.sql | 11.11 | 0.31 | Medium (μ ± σ) | - | [Details](16_listed_num_parameters.md) |

## Project Statistics
- Total SQL queries analyzed: 14
- Average query cost: 509.88
- Standard deviation: 719.50
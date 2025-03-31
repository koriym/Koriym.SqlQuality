# SQL Analysis Summary

## Query Analysis

| SQL File | Cost | Exec Time (ms) | Level | Issues | Report |
|----------|------|----------------|-------|---------|--------|
| 1\_full\_table\_scan.sql | 503.85 | 6.01 | Medium (μ ± σ) | FullTableScan | [Details](1\_full\_table\_scan.md) |
| 2\_filesort.sql | 272.55 | 0.20 | Medium (μ ± σ) | IneffectiveSort | [Details](2\_filesort.md) |
| 3\_function\_on\_indexed\_column.sql | 503.85 | 2.81 | Medium (μ ± σ) | FunctionInvalidatesIndex, FullTableScan | [Details](3\_function\_on\_indexed\_column.md) |
| 4\_no\_index\_on\_join.sql | 1468.14 | 27.52 | Medium (μ ± σ) | IneffectiveJoin, TemporaryTableGrouping | [Details](4\_no\_index\_on\_join.md) |
| 5\_multiple\_wildcard\_like.sql | 503.85 | 4.99 | Medium (μ ± σ) | FullTableScan, IneffectiveLikePattern | [Details](5\_multiple\_wildcard\_like.md) |
| 6\_implicit\_type\_conversion.sql | 202.50 | 1.36 | Medium (μ ± σ) | FullTableScan, ImplicitTypeConversion | [Details](6\_implicit\_type\_conversion.md) |
| 7\_temporary\_table\_grouping.sql | 202.50 | 1.08 | Medium (μ ± σ) | TemporaryTableGrouping | [Details](7\_temporary\_table\_grouping.md) |
| 8\_suboptimal\_or\_condition.sql | 503.85 | 7.17 | Medium (μ ± σ) | FullTableScan, ImplicitTypeConversion | [Details](8\_suboptimal\_or\_condition.md) |
| 9\_inefficient\_in\_query.sql | 3483.85 | 12.51 | ⚠️⚠️Very High (> μ + 2σ) | FullTableScan, IneffectiveRangeScan, IneffectiveSort | [Details](9\_inefficient\_in\_query.md) |
| 10\_redundant\_join.sql | 85.25 | 6.45 | Medium (μ ± σ) | - | [Details](10\_redundant\_join.md) |
| 11\_nested\_loop.sql | 276.61 | 0.45 | Medium (μ ± σ) | FullTableScan | [Details](11\_nested\_loop.md) |
| 12\_select1.sql | 1.00 | 0.07 | Medium (μ ± σ) | - | [Details](12\_select1.md) |
| 15\_listed\_parameters.sql | 101.75 | 0.47 | Medium (μ ± σ) | FullTableScan, IneffectiveRangeScan | [Details](15\_listed\_parameters.md) |
| 16\_ineffective\_range\_scan.sql | 424.70 | 3.03 | Medium (μ ± σ) | FullTableScan, IneffectiveSort | [Details](16\_ineffective\_range\_scan.md) |
| 18\_select\_distinct.sql | 1.86 | 0.20 | Medium (μ ± σ) | TemporaryTableGrouping | [Details](18\_select\_distinct.md) |
| 19\_unnecessary\_distinct.sql | 0.35 | 0.17 | Medium (μ ± σ) | - | [Details](19\_unnecessary\_distinct.md) |
| 20\_multi\_table\_update.sql | 2195.78 | 6.57 | ⚠️High (μ + σ to μ + 2σ) | - | [Details](20\_multi\_table\_update.md) |
| 21\_low\_cardinality\_index.sql | 85.25 | 2.32 | Medium (μ ± σ) | - | [Details](21\_low\_cardinality\_index.md) |
| 24\_grouping\_operation.sql | 1.86 | 0.24 | Medium (μ ± σ) | - | [Details](24\_grouping\_operation.md) |
| 25\_pass\_the\_with\_clause.sql | 2888.93 | 14.20 | ⚠️⚠️Very High (> μ + 2σ) | FullTableScan, TemporaryTableGrouping | [Details](25\_pass\_the\_with\_clause.md) |

## Queries with Optimizer Impact

| SQL File | Base Access | Optimized Access | Cost Impact | Base Issues | Plan Changes |
|:----------|:------------|:----------------|:------------|:------------|:-------------|
| 11\_nested\_loop.sql | ALL, 4956 rows, 100.0% | ALL, 1000 rows, 10.0% → ref, using idx\_posts\_user\_id, 4 rows, 100.0% | -45.1% | FullTableScan | - |
| 16\_ineffective\_range\_scan.sql | ALL, 2000 rows, 100.0% | ALL, 2000 rows, 11.1% | -80.7% | FullTableScan, IneffectiveSort | Filtering: 100.0% → 11.1%, Cost(R/E): 2.5/200.0 → 180.3/22.2 |
| 25\_pass\_the\_with\_clause.sql | ALL, 10023 rows, 100.0% | ref, using idx\_users\_status\_created, 800 rows, 100.0% → ref, using user\_id, 10 rows, 100.0% | 181.4% | FullTableScan, TemporaryTableGrouping | - |

## Statistics

- Total queries analyzed: 20
- Average query cost: 685.41
- Standard deviation: 987.87


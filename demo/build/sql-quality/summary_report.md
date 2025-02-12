# SQL Analysis Summary

## Query Analysis

| SQL File | Cost | Exec Time (ms) | Level | Issues | Report |
|----------|------|----------------|-------|---------|--------|
| 4\_no\_index\_on\_join.sql | 1462.81 | 14.42 | ⚠️High (μ + σ to μ + 2σ) | IneffectiveJoin, TemporaryTableGrouping | [Details](4\_no\_index\_on\_join.md) |
| 10\_redundant\_join.sql | 85.25 | 2.97 | Medium (μ ± σ) | - | [Details](10\_redundant\_join.md) |
| 11\_nested\_loop.sql | 274.18 | 0.22 | Medium (μ ± σ) | FullTableScan | [Details](11\_nested\_loop.md) |
| 21\_low\_cardinality\_index.sql | 85.25 | 0.64 | Medium (μ ± σ) | - | [Details](21\_low\_cardinality\_index.md) |

## Queries with Optimizer Impact

| SQL File | Base Access | Optimized Access | Cost Impact | Base Issues |
|----------|-------------|------------------|-------------|--------------|
| 11\_nested\_loop.sql | ALL, 4897 rows, 100.0% | ALL, 1000 rows, 10.0% → ref, using idx\_posts\_user\_id, 4 rows, 100.0% | -44.9% | FullTableScan |

## Statistics

- Total queries analyzed: 4
- Average query cost: 476.87
- Standard deviation: 574.43


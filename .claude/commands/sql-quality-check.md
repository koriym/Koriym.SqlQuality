# SQL Quality Check

Analyze SQL files for performance issues. Suitable for CI pipelines.

## Arguments
- `$ARGUMENTS`: SQL directory and params file (e.g., "tests/sql tests/params/sql_params.php")

## Steps

### 1. Run Analysis

```bash
php bin/sql-quality analyze \
  --sql-dir="$(echo $ARGUMENTS | cut -d' ' -f1)" \
  --params="$(echo $ARGUMENTS | cut -d' ' -f2)" \
  --format=json
```

### 2. Report Results

Parse the JSON output and report:

**Summary Table:**
| SQL File | Cost | Issues |
|----------|------|--------|
| file.sql | 497.95 | FullTableScan, IneffectiveSort |

**Issue Severity:**
- CRITICAL: FullTableScan, IneffectiveJoin (block release)
- WARNING: IneffectiveSort, TemporaryTableGrouping (review recommended)
- INFO: Other issues (minor impact)

### 3. Exit Status for CI

- **Exit 0**: No critical issues found
- **Exit 1**: Critical issues detected (FullTableScan, IneffectiveJoin)

Report format for CI:
```
SQL Quality Check: FAILED
- 3 critical issues found
- 2 warnings found

Critical:
  1_full_table_scan.sql: FullTableScan (cost: 497.95)
  4_no_index_on_join.sql: IneffectiveJoin (cost: 234.50)

Run '/sql-quality-fix' to auto-fix these issues.
```

### 4. Output

DO NOT modify any files. Only report findings.

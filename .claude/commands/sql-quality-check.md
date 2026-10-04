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
  --format=json \
  --fail-on=critical
```

Keep the exit status. stdout is the JSON report and nothing else.

### 2. Report Results

Parse the JSON. Severity comes from the report: `queries[].issues[].severity`
for each issue, `summary.issues_by_severity` for the totals. Do not classify
issue types yourself.

**Summary Table:**
| SQL File | Cost | Issues |
|----------|------|--------|
| file.sql | 497.95 | FullTableScan (Critical), IneffectiveSort (Warning) |

**Skipped files:**

List every entry of `skipped` with its reason. They were not analyzed.

### 3. Exit Status for CI

Report the status the CLI returned:

- **Exit 0**: no issue reached the `--fail-on` level
- **Exit 1**: an issue reached the `--fail-on` level
- **Exit 2**: usage, connection or params file error

Report format for CI:
```
SQL Quality Check: FAILED (exit 1)
- Critical: 2, Warning: 6, Info: 2

Critical:
  1_full_table_scan.sql: FullTableScan (cost: 497.95)
  4_no_index_on_join.sql: IneffectiveJoin (cost: 234.50)

Skipped:
  14_not_found.sql: SQL file not found: tests/sql/14_not_found.sql

Run '/sql-quality-fix' to auto-fix these issues.
```

### 4. Output

DO NOT modify any files. Only report findings.

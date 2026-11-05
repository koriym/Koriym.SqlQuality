---
name: sql-params-generate
description: Write the parameter file sql-quality needs for SQL files with :name placeholders, using values that exist in the database. Use when sql-quality skips files with UnboundParameter, or when asked to generate SQL parameters.
---

# SQL Parameters Generator

Give every `:name` placeholder of a SQL directory a value, so `sql-quality` can EXPLAIN the statements.

## Arguments

`$ARGUMENTS`: the SQL directory, optionally the params file to write (default `<dir>/params.json`).

## Steps

### 1. Find what is missing

```bash
SQ=vendor/bin/sql-quality; [ -x "$SQ" ] || SQ=bin/sql-quality
$SQ analyze --sql-dir=<dir> [--params=<existing params file>]
```

Every `skipped` entry `UnboundParameter: a, b` names the parameters a file still needs. Entries for
`EmptyListParameter` need a non-empty list. Keep the parameters an existing file already has.

### 2. Pick values from the data

Read the statement, find the column each placeholder is compared with, and take a real value:

```sql
SELECT status, COUNT(*) FROM posts GROUP BY status ORDER BY 2 DESC LIMIT 5;
SELECT MIN(created_at), MAX(created_at) FROM posts;
```

- Equality on a key: a value that exists, so the plan is the one production sees.
- Ranges and dates: bounds inside the data.
- `IN (:list)`: a JSON array of two to five existing values.
- `LIMIT` / `OFFSET`: integers, not strings.
- `LIKE`: the pattern the application builds, wildcards included.

Prefer values that return some rows: `/sql-quality-fix` needs rows to check a rewrite.

### 3. Write the file

JSON keyed by SQL file name; files without placeholders need no entry.

```json
{
  "1_full_table_scan.sql": {"min_views": 1000},
  "2_filesort.sql": {"status": "published", "limit": 10},
  "15_listed_parameters.sql": {"listed_params": ["User 1", "User 10"]}
}
```

A PHP file returning the same array also works (`--params=params.php`).

### 4. Check

Run step 1 again with `--params=<file>`. No `UnboundParameter` or `EmptyListParameter` may remain.
Report the other `skipped` entries (DDL, syntax errors) as they are.

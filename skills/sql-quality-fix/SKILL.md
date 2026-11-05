---
name: sql-quality-fix
description: Fix MySQL performance problems in SQL files and prove each fix - rewrites are checked to return the same rows, indexes are measured as invisible indexes before anyone adds them. Use when asked to fix, optimize or speed up the SQL of a project.
---

# SQL Quality Fix

Fix the problems `sql-quality analyze` reports, one at a time, and keep only fixes the tool has verified.

## Arguments

`$ARGUMENTS`: the SQL directory, optionally the params file, and options.

- `sql/ sql/params.php`
- `sql/ sql/params.php --keep-indexes`

`--keep-indexes`: keep the indexes `try-index` finds effective, in the database. Without it no index
is left behind; the effective ones are reported as DDL for a migration.

## Ground rules

- A rewrite is kept only when `compare` says `equivalent: true` with `both_empty: false`.
- An index is recommended only when `try-index` says `effective: true`.
- Never run `CREATE INDEX`, `DROP INDEX` or any other DDL yourself; `try-index` adds the trial index
  INVISIBLE, so no other session's plan changes, and drops it again.
- Work on a development database. `try-index` builds a real index: it reads the whole table.

## Steps

### 0. Baseline

```bash
SQ=vendor/bin/sql-quality; [ -x "$SQ" ] || SQ=bin/sql-quality
mkdir -p build/sql-quality
$SQ analyze --sql-dir=<dir> --params=<params> > build/sql-quality/baseline.json
```

Exit 2: stop and report stderr. Files in `skipped` with `UnboundParameter` need parameters first
(`/sql-params-generate`).

### 1. Rewrites

For each issue whose `suggestion.kind` is `rewrite`, or whose type is fixed by rewriting
(`FunctionInvalidatesIndex`, `ImplicitTypeConversion`, `OrderByRand`, `DeepOffset`, `UnnecessaryDistinct`,
`IneffectiveUnion`, `DependentSubquery`), write the rewritten statement and check it:

```bash
$SQ compare --before=<dir>/<file> --after-sql="<rewritten SQL>" --params=<params>
```

- Exit 0, `equivalent: true`, `both_empty: false`: replace the file's statement. Report
  `cost_change_percent` and `issues.resolved`.
- `both_empty: true`: the parameters return no rows, so the check proves nothing. Pick parameters that
  return rows (`--before-params` / `--after-params` take a JSON object) and compare again.
- Exit 1: the rows differ. Do not apply it; try another rewrite or list it for manual review.
- `same_order: false` matters only when the statement has an ORDER BY.

A rewrite that only reorders rows, changes column names, or depends on `NOW()` / `RAND()` cannot pass;
list it for manual review instead.

### 2. Indexes

For each issue with `suggestion.kind` `index`, try its `suggestion.ddl` against the file:

```bash
$SQ try-index --sql-file=<dir>/<file> --params=<params> --index="<suggestion.ddl>" [--keep]
```

Pass `--keep` only with `--keep-indexes`. Exit 0 is effective (the plan uses the index and the cost
fell at least 5%), exit 1 is not. Record `index.ddl`, `cost_change_percent` and `issues.resolved`
either way. Two suggestions for the same table: try the composite one first.

### 3. Verify

```bash
$SQ analyze --sql-dir=<dir> --params=<params> --baseline=build/sql-quality/baseline.json > build/sql-quality/after.json
```

`baseline.new` must be empty; if a rewrite introduced an issue, revert it. `baseline.unverified` lists
files that no longer analyze; revert the change that caused it.

### 4. Report

Write `build/sql-quality/fix-report.md`:

```
SQL Quality Fix

Total cost 441706 → 120330 (-72.8%) · issues 47 → 21 · new 0

Rewrites (verified equivalent)
  3_function_on_indexed_column.sql  DATE(created_at) = :d → range   resolved FunctionInvalidatesIndex@posts.created_at

Indexes (effective, as invisible indexes)
  CREATE INDEX `idx_posts_view_count` ON `posts` (`view_count`)   -95.8%   kept: no

Not applied
  30_order_by_rand.sql  OrderByRand@posts  rewrite changes the result (random order); review by hand
  CREATE INDEX ... ON comments (content)   not used by the plan
```

Take every number from the tool's output; do not estimate.

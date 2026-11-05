---
name: sql-quality-check
description: Check SQL files for MySQL performance problems (full table scans, ineffective joins, filesorts, functions on indexed columns, implicit conversions and more) and report them, CI style, without changing anything. Use when asked to check, lint or review the SQL of a project, or to gate a change on new SQL problems.
---

# SQL Quality Check

Report the performance problems of a directory of SQL files. Read-only: change no file and no schema.

## Arguments

`$ARGUMENTS`: the SQL directory, then optionally the params file and a baseline report.

- `sql/`
- `sql/ sql/params.php`
- `sql/ sql/params.php build/sql-quality/baseline.json`

## Steps

### 1. Find the command

```bash
SQ=vendor/bin/sql-quality; [ -x "$SQ" ] || SQ=bin/sql-quality
```

The connection comes from `SQL_QUALITY_DSN`, `SQL_QUALITY_USER` and `SQL_QUALITY_PASSWORD`
(default `mysql:host=127.0.0.1;dbname=test`, `root`, empty). Prefer the environment variables
to `--password` so the password does not land in the transcript.

### 2. Run the analysis

```bash
mkdir -p build/sql-quality
$SQ analyze --sql-dir=<dir> [--params=<params file>] [--baseline=<baseline>] --fail-on=critical > build/sql-quality/report.json
```

Keep the exit status: 0 nothing reached the level, 1 something did, 2 the run failed (stderr says why;
stop there). stdout is the JSON report and nothing else; its shape is `schema/analyze-report.schema.json`.

Every `*.sql` file of the directory is analyzed. A file the params file does not cover runs without
parameters; if it has placeholders it lands in `skipped` as `UnboundParameter: name, ...`. Run
`/sql-params-generate` for those.

With a baseline, `--fail-on` counts only `baseline.new`: known problems do not fail the run.

### 3. Report

Take severity, ids and suggestions from the report; do not classify issues yourself.

```
SQL Quality Check: FAILED (exit 1)
Critical 2 · Warning 6 · Info 2 · analyzed 30 · skipped 2

Critical
  1_full_table_scan.sql   FullTableScan@posts            index: CREATE INDEX idx_posts_view_count ON posts (view_count)
  26_join_without_index.sql IneffectiveJoin@o            index: ...

Hidden by the optimizer (optimizer_impact.hidden_issues)
  11_nested_loop.sql      FullTableScan@posts

Skipped
  16_listed_num_parameters.sql  UnboundParameter: listed_params
```

With a baseline, lead with `baseline.new` and `baseline.resolved`, and list `baseline.unverified`
files: they were not analyzed this time, so their issues are neither fixed nor known to persist.

`optimizer_impact.hidden_issues` are problems the optimizer currently works around; they can come
back when statistics or data volume change. Report them, below the issues of the default plan.

Suggest `/sql-quality-fix` when there is something to fix.

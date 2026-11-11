# Koriym.SqlQuality

[![Continuous Integration](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml)
[![Coding Standards](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml)

[Japanese](README.ja.md)

MySQL query analysis for coding agents and CI. It measures, the agent decides.

A coding agent can propose a rewrite or an index, but it cannot tell by reading the SQL whether the
rewrite returns the same rows, whether MySQL will use the index, or whether a problem is only hidden
by the optimizer today. `sql-quality` answers those questions against a real database and prints JSON:

- **analyze** / **explain**: `EXPLAIN FORMAT=JSON`, `EXPLAIN ANALYZE`, `SHOW WARNINGS`, the schema and the
  optimizer trace go through 18 detectors. Every issue has a stable id, severity, evidence and, where
  one applies, a suggested index or rewrite.
- **compare**: runs the original and the rewritten SELECT and checks that they return the same rows.
- **try-index**: adds a candidate index *invisible*, measures the statement with it in this session
  only, and drops it.
- **--baseline**: tells new issues from known ones, so CI fails only on what a change introduced.

Each statement is also explained with the optimizer features turned off: `optimizer_impact.hidden_issues`
lists the problems the optimizer currently works around, which can come back when statistics or data
volume change.

## Requirements

- PHP 8.1+ with PDO MySQL
- MySQL 8.0.18+ (`EXPLAIN ANALYZE`; `try-index` uses invisible indexes)

## Installation

```bash
composer require --dev koriym/sql-quality
```

## Commands

The connection is read from `SQL_QUALITY_DSN`, `SQL_QUALITY_USER` and `SQL_QUALITY_PASSWORD`, or from
`--dsn`, `--user` and `--password` (default `mysql:host=127.0.0.1;dbname=test`, `root`, empty password).
Prefer the environment variables: a password on the command line ends up in shell history and agent transcripts.

stdout carries the JSON report and nothing else; errors go to stderr.

### analyze

```bash
vendor/bin/sql-quality analyze --sql-dir=sql/ --params=sql/params.json --fail-on=critical
```

Analyzes every `*.sql` file in the directory. `--params` maps a file name to its parameters
(a `.json` file, or a PHP file returning the same array); a file without an entry runs without
parameters, and one whose `:name` placeholders are not covered is listed in `skipped` as
`UnboundParameter: name, ...`.

```json
{
  "1_full_table_scan.sql": {"min_views": 1000},
  "15_listed_parameters.sql": {"names": ["Alice", "Bob"]}
}
```

`--baseline=FILE` takes an earlier `analyze` report and adds `baseline.new`, `resolved`, `persisting`
and `unverified` (files the earlier report had and this run did not analyze). With a baseline,
`--fail-on` counts only the new issues.

```bash
vendor/bin/sql-quality analyze --sql-dir=sql/ --params=sql/params.json > baseline.json   # on the main branch
vendor/bin/sql-quality analyze --sql-dir=sql/ --params=sql/params.json --baseline=baseline.json --fail-on=warning
```

### explain

```bash
vendor/bin/sql-quality explain --sql-file=sql/1_full_table_scan.sql --params='{"min_views":1000}'
vendor/bin/sql-quality explain --sql="SELECT * FROM posts WHERE user_id = :id" --params='{"id":1}'
echo "SELECT * FROM posts" | vendor/bin/sql-quality explain --sql-file=-
```

One statement, as a file, inline or from stdin. The output is a summary: the issues and the optimizer
impact. `--detail=full` adds the EXPLAIN JSON, `EXPLAIN ANALYZE`, `SHOW WARNINGS`, the schema and the
optimizer trace.

```json
{
  "file": "1_full_table_scan.sql",
  "sql": "SELECT * FROM posts WHERE view_count > 1000",
  "executed": true,
  "skipped_reason": null,
  "cost": 503.45,
  "execution_time_ms": 4.87,
  "issues": [
    {
      "id": "FullTableScan@posts",
      "type": "FullTableScan",
      "message": "Full table scan detected.",
      "documentation": "https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan",
      "severity": "Critical",
      "confidence": 0.95,
      "evidence": {"table_name": "posts", "rows_examined_per_scan": 4952, "filtered": "33.33", "attached_condition": "(`test`.`posts`.`view_count` > 1000)"},
      "suggestion": {"kind": "index", "description": "Add a composite index covering view_count", "ddl": "CREATE INDEX idx_posts_view_count ON posts (view_count)"}
    }
  ],
  "optimizer_impact": {"cost_without_optimizer": 503.45, "cost_reduction_percent": 0, "hidden_issues": []}
}
```

The issue `id` is the type and the place (`FullTableScan@posts`, `FunctionInvalidatesIndex@posts.created_at`);
it holds no row counts or costs, so it stays the same across runs while the problem persists. A repeat at
the same place gets `#2`, `#3`; `@`, `.`, `#` and `\` inside a name are backslash-escaped.

### compare

```bash
vendor/bin/sql-quality compare \
  --before=sql/3_function_on_indexed_column.sql \
  --after-sql="SELECT * FROM posts WHERE created_at >= :d AND created_at < :d + INTERVAL 1 DAY" \
  --params='{"d":"2024-01-01"}'
```

Runs both SELECTs in a read-only session and compares the result sets: `equivalent` (same columns, same
multiset of rows), `same_order`, the row counts and checksums, the change in cost and time, and the
issues the rewrite resolved or introduced. `both_empty: true` means neither statement returned a row,
so the check proves nothing; choose parameters that return rows. `--before-params` / `--after-params`
override `--params` for one side. Exit 0 when equivalent, 1 when not. Values are compared with the
PHP type the driver returns (`5` and `'5'` differ), and floating-point values to 17 significant digits, so a rewrite that only changes the order of a `DOUBLE` `SUM()` can
come out not equivalent: the application would see a different value.

### try-index

```bash
vendor/bin/sql-quality try-index --sql-file=sql/1_full_table_scan.sql --params=sql/params.json \
  --index="CREATE INDEX idx_posts_view_count ON posts (view_count)"
```

`--index` takes a suggestion's `ddl` or the short `posts(view_count, created_at)`. The index is added
`INVISIBLE, ALGORITHM=INPLACE, LOCK=NONE`, the statement is measured with `use_invisible_indexes=on` in
this session only, and the index is dropped. The report says whether the plan `used` it and whether it
was `effective` (used, and the cost fell by `--min-improvement`, default 5%). Exit 0 when effective, 1 when not.

`--keep` keeps an effective index and makes it visible; an ineffective one is dropped either way.
Building an index reads the whole table and needs the ALTER privilege: run it against a development
database.

- The ALTER and the DROP wait for a metadata lock behind open transactions on the table, and other
  statements queue behind that wait, so the session's `lock_wait_timeout` is lowered to 10 seconds:
  a busy table makes the trial fail (exit 2) instead of stalling it.
- Before the index is added, stderr names the `DROP INDEX` statement that removes it. A run killed
  mid-trial can leave the `INVISIBLE` index behind; run that statement, or look for
  `sqlq_trial_%` / your index name in `information_schema.statistics`.
- A table of another schema (`reporting.posts(view_count)`) is refused: connect to that schema with
  `--dsn` and name the table alone. A table qualified with the connection's own database, as a
  suggestion's `ddl` names it for `FROM app.posts`, is accepted.
- A table the statement reads that already has an invisible index is refused
  (`InvisibleIndexesPresent`): `use_invisible_indexes=on` would enable it too and the result could
  not be attributed to the trial index.

### Exit codes

| Code | Meaning |
|------|---------|
| `0` | The check passed |
| `1` | An issue reached `--fail-on`, a rewrite is not equivalent, or an index is not effective |
| `2` | The command could not run: usage, connection, parameters, or a statement MySQL rejects. stderr names the exception, e.g. `UnboundParameter: target_date` |

### JSON Schema

Every command's output follows a schema in [`schema/`](schema/): `analyze-report`, `explain-report`,
`compare-report` and `try-index-report`.

## Claude Code plugin

The plugin bundles three skills that drive the commands:

```bash
/plugin marketplace add koriym/Koriym.SqlQuality
/plugin install sql-quality@sql-quality
```

| Skill | What it does |
|-------|--------------|
| `/sql-quality-check sql/ sql/params.json [baseline.json]` | Reports the issues, CI style; changes nothing |
| `/sql-quality-fix sql/ sql/params.json [--keep-indexes]` | Rewrites only what `compare` proves equivalent, recommends only indexes `try-index` proves effective |
| `/sql-params-generate sql/` | Writes the params file from values that exist in the database |

In this repository, `.claude/settings.json` enables the plugin when you trust the folder.

## Library

```php
use Koriym\SqlQuality\JsonReportGenerator;
use Koriym\SqlQuality\SqlFileAnalyzer;

$analyzer = new SqlFileAnalyzer($pdo, sqlDir: __DIR__ . '/sql');
$run = $analyzer->analyzeDirectory(['1_full_table_scan.sql' => ['min_views' => 1000]]);
$report = (new JsonReportGenerator())->generate($run);

$result = $analyzer->analyzeSql('SELECT * FROM posts WHERE user_id = :id', ['id' => 1]);
```

`RewriteComparator`, `IndexTrial` and `Baseline` are the classes behind `compare`, `try-index` and `--baseline`.

## Writing a Detector

A detector is a class in `src/Detector/` implementing `DetectorInterface`. `detect()` receives a `QueryContext` and returns a list of `Finding`, one per table or plan node it matched:

```php
final class FullTableScanDetector implements DetectorInterface
{
    /** @return list<Finding> */
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($table['access_type'] === 'ALL') {
                $findings[] = new Finding(array_intersect_key($table, array_flip(['table_name', 'rows_examined_per_scan', 'possible_keys', 'key'])));
            }
        }

        return $findings;
    }
}
```

`QueryContext` holds the interpolated `sql`, `explain` (`EXPLAIN FORMAT=JSON`), `explainAnalyze`, `warnings` (`SHOW WARNINGS`), `schema` (information_schema per table, keyed by `posts`, or `reporting.posts` when the statement qualifies the table) and `optimizerTrace` (an excerpt of `information_schema.OPTIMIZER_TRACE` for the default-optimizer `EXPLAIN`, `null` when the trace is unavailable: not captured, unreadable or truncated). `tables()`, `tableAccesses()`, `nestedLoops()`, `warningsWithCode()`, `indexColumns()`, `aliases()`, `schemaFor()`, `columnType()`, `primaryKeyColumns()` and `optimizerTraceFor()` read them.

`Finding::$evidence` holds the values the detector based its decision on: use the EXPLAIN key names for values taken from the plan, and include `table_name` when the finding is about one table, and `column` when it is about one column; the issue id is built from them. It is reported as `evidence` on the issue. `severity`, `confidence` and `suggestion` are optional; when set they replace the defaults for the warning type.

Test a detector against the recorded plans in `tests/fixtures/`: `Fixture::load('1_full_table_scan.sql')` returns the `QueryContext` for `tests/sql/1_full_table_scan.sql`, and `tests/fixtures/expected.php` lists the types every fixture must produce. To register a detector, add it to the `ExplainAnalyzer` constructor, its type to `WarningType` in `src/Types.php` and to the `type` enum of `schema/analyze-report.schema.json` and `schema/explain-report.schema.json`, its message to `ExplainAnalyzer::MESSAGES`, and its page to `docs/issues/`.

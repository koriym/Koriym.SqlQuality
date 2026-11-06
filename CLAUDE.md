# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Koriym.SqlQuality is a MySQL query analyzer for coding agents and CI. It analyzes SQL with `EXPLAIN FORMAT=JSON`, `EXPLAIN ANALYZE` and the optimizer trace, with and without the optimizer features, and prints JSON. It also verifies fixes: `compare` checks that a rewritten SELECT returns the same rows, `try-index` measures a candidate index as an invisible index and drops it, and `--baseline` separates new issues from known ones. The tool measures; the agent calling it decides and writes the prose.

## Common Commands

```bash
# Run all tests
composer test

# Run a single test
./vendor/bin/phpunit tests/ExplainExplainerTest.php
./vendor/bin/phpunit --filter testMethodName

# Check coding standards
composer cs

# Fix coding standards
composer cs-fix

# Run static analysis (Psalm)
composer sa

# Run all quality checks (cs + sa + test)
composer tests

# Full build (clean + cs + sa + coverage + metrics)
composer build

# Generate test coverage
composer pcov
```

## Architecture

### Core Classes

- **Cli** (`src/Cli.php`, run by `bin/sql-quality`): the `analyze`, `explain`, `compare` and `try-index` commands. stdout is JSON only; exit 0 pass, 1 the check failed, 2 could not run. Each command accepts a fixed option list; anything else is a usage error.
- **SqlFileAnalyzer** (`src/SqlFileAnalyzer.php`): builds the `QueryContext` (EXPLAIN, EXPLAIN ANALYZE in a read-only session, SHOW WARNINGS, schema, optimizer trace), runs the detectors, times read-only SELECTs, and repeats the EXPLAIN with the optimizer features off (`without_optimizer`, `hiddenIssues()`).
- **ExplainAnalyzer** (`src/ExplainAnalyzer.php`): runs every detector over a `QueryContext` and returns the issues with a stable `id` (`Type@table.column`), severity, confidence, evidence and suggestion.
- **ParameterBinder**: binds `:name` placeholders; throws `UnboundParameter` naming the missing ones.
- **SchemaReader**: information_schema rows for the tables a statement references.
- **RewriteComparator** / **ResultFingerprint**: the `compare` command; result sets are compared by row count and ordered / order-independent checksums.
- **IndexTrial** / **IndexDefinition**: the `try-index` command. Only plain `CREATE INDEX name ON table (cols)` or `table(cols)` is accepted; the index is added INVISIBLE and always dropped (or, with keep and an effective result, made visible).
- **Baseline**: diffs an `AnalysisRun` against an earlier `analyze` report by file and issue id.
- **JsonReportGenerator**: the `analyze` report; `query()` is the per-statement part `explain` shares.

### Detector Pattern

Issue detectors implement `DetectorInterface` (`src/Detector/DetectorInterface.php`):
```php
interface DetectorInterface {
    /** @return list<Finding> */
    public function detect(QueryContext $context): array;
}
```

`QueryContext` (`src/QueryContext.php`) carries the interpolated SQL, `EXPLAIN FORMAT=JSON`, `EXPLAIN ANALYZE`, `SHOW WARNINGS`, the schema of the referenced tables and an excerpt of `information_schema.OPTIMIZER_TRACE` (`OptimizerTrace::excerpt()`, captured for the default-optimizer `EXPLAIN` only, `null` when unavailable). `Finding` (`src/Detector/Finding.php`) carries the evidence, one per matching table. Detectors are registered in the `ExplainAnalyzer` constructor. `tests/fixtures/*.json` are recorded contexts (`Fixture::load()`, re-record with `php tests/fixtures/record.php`); `DetectorCorpusTest` checks every fixture against `tests/fixtures/expected.php`.

Existing detectors in `src/Detector/`:
- CartesianProductDetector
- DeepOffsetDetector
- DependentSubqueryDetector
- EstimateDivergenceDetector
- ExcessiveDerivedTablesDetector
- FullTableScanDetector
- FunctionInvalidatesIndexDetector
- ImplicitTypeConversionDetector
- IneffectiveJoinDetector
- IneffectiveLikePatternDetector
- IneffectiveRangeScanDetector
- IneffectiveSortDetector
- IneffectiveUnionDetector
- LowCardinalityIndexDetector
- MultiTableUpdateDetector
- OrderByRandDetector
- TemporaryTableGroupingDetector
- UnnecessaryDistinctDetector

### Type Definitions

Psalm type definitions are centralized in `src/Types.php`. Import types using:
```php
/** @psalm-import-type ExplainResult from Types */
```

### Output contract

The JSON of every command follows `schema/*.schema.json`; `JsonSchemaTest` validates real CLI output against them. A change to the output needs the schema, `src/Types.php`, the skills in `skills/` and both READMEs updated together.

## Testing

Tests require a MySQL 8.0 database with `tests/sql/schema.sql` loaded (`SQL_QUALITY_DSN`, `SQL_QUALITY_USER`, `SQL_QUALITY_PASSWORD`, default `mysql:host=127.0.0.1;dbname=test` as root); without one the database tests are skipped, and `SQL_QUALITY_REQUIRE_DB=1` makes them fail instead. Test SQL files and parameters are in `tests/sql/` and `tests/params/`.

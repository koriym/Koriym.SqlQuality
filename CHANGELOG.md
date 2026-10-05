# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-10-05

### Added
- `--fail-on=LEVEL` option and exit codes 0 / 1 / 2 for the CLI
- Skipped files and their reasons in the analysis result and the JSON output
- `ReadOnlySession` guard around query timing and `EXPLAIN ANALYZE`
- MySQL service and database-backed tests in CI
- `SqlFileAnalyzer::queryContext()` returning the `QueryContext` the detectors receive
- `ExplainAnalyzeParser` turning the text of `EXPLAIN ANALYZE` into a list of plan nodes
- `DependentSubqueryDetector` detecting correlated subqueries that run once per outer row
- `CartesianProductDetector` detecting joined tables with no key connecting them to the preceding tables
- `DeepOffsetDetector` detecting `LIMIT`/`OFFSET` pagination that scans and discards a large number of rows
- `OrderByRandDetector` detecting `ORDER BY RAND()` forcing a filesort over every matching row
- `EstimateDivergenceDetector` detecting `EXPLAIN ANALYZE` nodes where the row estimate diverges from the actual row count
- `sql-quality explain` command analyzing a single SQL file and printing structured JSON
- JSON Schema for the `analyze` and `explain` CLI output (`schema/analyze-report.schema.json`, `schema/explain-report.schema.json`)
- `NotExplainable`, `NotReadOnlySelect`, `InvalidExplainResult` and `QueryFailed` exceptions thrown by `SqlFileAnalyzer`, all extending its `RuntimeException`
- `InvalidSeverityThreshold` (rejected `--fail-on` value), `ReadOnlySessionUnavailable` (driver cannot report the session read-only flag) and `ActiveTransactionRejected` (`ReadOnlySession::run()` called on a connection with an open transaction) exceptions
- `OptimizerTrace::excerpt()` and `QueryContext::$optimizerTrace` / `optimizerTraceFor()`: an excerpt of `information_schema.OPTIMIZER_TRACE` (range analysis, considered access paths and index recheck per table of the final plan, subquery transformations and condition processing per statement) captured with the default-optimizer `EXPLAIN FORMAT=JSON`, `null` when the server has no trace, the privilege is missing or the trace was truncated
- `context.optimizer_trace` in the `explain` JSON output and in `tests/fixtures/*.json`

### Changed
- `analyzeSQLFiles()` returns results and skipped files instead of printing progress
- `analyzeSQLFiles()` and `analyze()` no longer take an output directory
- `--format=markdown` requires `--output`
- `FullTableScan` and `IneffectiveJoin` are reported as `Critical`
- `FullTableScanDetector` excludes internal temp tables (`<derived2>`, `<union1,2>`), reports `Info` below 100 rows examined, and suggests an index or review based on the attached condition
- `IneffectiveSortDetector` reports every `ordering_operation` with `using_filesort` over a full scan or 1000+ examined rows, and drops the query-cost, data-size, and backward-scan rules
- `FullTableScanDetector` reads the optimizer trace when an index exists but is not used: `evidence.optimizer_trace` names the cause (`cost`, `index_merge_union` or `join_key_only`) with the rows and costs the optimizer compared, and the suggestion follows the cause instead of the fixed review text
- `TemporaryTableGroupingDetector` reports every `grouping_operation` (or the `ordering_operation` above one) that uses a temporary table, with the tables beneath it, and ignores `union_result` and `duplicates_removal` temporary tables
- `LowCardinalityIndexDetector` judges by the leading key column's `CARDINALITY` over `table_rows` (at most 1%) on lookups examining 500+ rows, instead of `filtered`, and suggests a review
- `UnnecessaryDistinctDetector` checks the SELECT list of single-table `SELECT DISTINCT` statements against the table's primary key from the schema, instead of looking for a column named `id`, and suggests the statement without `DISTINCT`
- `IneffectiveJoinDetector` reports only inner tables of a `nested_loop` scanned in full (`ALL` / `index`) or joined through a join buffer, drops the row-count and cost thresholds, and suggests an index on the join column or a review
- `ImplicitTypeConversionDetector` reports a string-typed column (by schema) compared with a numeric literal in `attached_condition` or `index_condition`, at confidence 0.95 when MySQL warns 1739, and suggests quoting the literal
- `FunctionInvalidatesIndexDetector` reports a column wrapped in a function in `attached_condition` only when the column is in an index, names those indexes, and suggests a range rewrite for `DATE(col) = 'D'`
- `IneffectiveLikePatternDetector` reports each column with a leading-wildcard `LIKE` (not only a both-sided one) when the table is fully scanned or filters below 25%, and suggests a review
- `IneffectiveRangeScanDetector` reports only `index_merge` and `range` scans examining 1000+ rows with `filtered` below 20%, dropping the `ALL` + `IN`, `OR`, and multiple `possible_keys` rules
- `IneffectiveUnionDetector` reports the query specification count as evidence and suggests `UNION ALL` when the SQL has a plain `UNION`, or a review otherwise
- `ExcessiveDerivedTablesDetector` names each materialized derived table and the tables inside its subquery in evidence
- `MultiTableUpdateDetector` adds the summed `rows_examined_per_scan` of the updated tables to evidence
- `DetectorInterface::detect()` takes a `QueryContext` and returns a list of `Finding` instead of a bool
- Detected issues carry `detector`, `evidence` and `suggestion`; a detector reports each matching table separately
- `ExplainAnalyzer::analyze()` takes a `QueryContext` instead of the EXPLAIN array

### Fixed
- Per-query Markdown report was overwritten with the no-optimizer prompt
- `SqlSafetyClassifier` read the body of a MySQL versioned comment (`/*!NNNNN ... */`) as a comment, and a backslash inside a backtick identifier as an escape; either hid a second statement from the stacked-statement check, so it reached `EXPLAIN` and was executed
- `ReadOnlySession::run()` on a connection with an open transaction left that transaction writable
- CLI exits 2 instead of 255 when the params file cannot be loaded, and 2 instead of 0 with an empty report when the report cannot be encoded as JSON (invalid UTF-8 in the SQL)

## [0.2.0] - 2026-01-15

### Added
- **Claude Code Skills integration** - AI-powered SQL analysis and detector development assistance
- **New detector: `UnnecessaryDistinctDetector`** - Detects redundant DISTINCT operations
- **New detector: `LowCardinalityIndexDetector`** - Identifies inefficient low-cardinality indexes

### Improved
- Enhanced type safety with Psalm domain types throughout the codebase
- Added `#[Override]` attributes for better IDE support (requires symfony/polyfill-php83)

### Fixed
- Division by zero errors in `IneffectiveJoinDetector` and `SqlFileAnalyzer`
- Type safety in `QueryStatisticsCalculator` variance calculation

## [0.1.7] - 2025-01-14

Previous releases (details to be added)

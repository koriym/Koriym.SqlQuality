# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- `--fail-on=LEVEL` option and exit codes 0 / 1 / 2 for the CLI
- Skipped files and their reasons in the analysis result and the JSON output
- `ReadOnlySession` guard around query timing and `EXPLAIN ANALYZE`
- MySQL service and database-backed tests in CI
- `SqlFileAnalyzer::queryContext()` returning the `QueryContext` the detectors receive
- `ExplainAnalyzeParser` turning the text of `EXPLAIN ANALYZE` into a list of plan nodes
- `DependentSubqueryDetector` detecting correlated subqueries that run once per outer row

### Changed
- `analyzeSQLFiles()` returns results and skipped files instead of printing progress
- `analyzeSQLFiles()` and `analyze()` no longer take an output directory
- `--format=markdown` requires `--output`
- `FullTableScan` and `IneffectiveJoin` are reported as `Critical`
- `DetectorInterface::detect()` takes a `QueryContext` and returns a list of `Finding` instead of a bool
- Detected issues carry `detector`, `evidence` and `suggestion`; a detector reports each matching table separately
- `ExplainAnalyzer::analyze()` takes a `QueryContext` instead of the EXPLAIN array

### Fixed
- Per-query Markdown report was overwritten with the no-optimizer prompt

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

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Koriym.SqlQuality is a MySQL query analyzer that detects potential performance issues in SQL files and provides AI-powered optimization recommendations. It analyzes SQL queries using `EXPLAIN FORMAT=JSON` and `EXPLAIN ANALYZE`, comparing execution plans with and without MySQL optimizer to identify performance bottlenecks.

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

- **SqlFileAnalyzer** (`src/SqlFileAnalyzer.php`): Main entry point. Orchestrates SQL analysis by:
  - Reading SQL files and interpolating parameters
  - Executing `EXPLAIN FORMAT=JSON` and `EXPLAIN ANALYZE`
  - Running analysis with and without MySQL optimizer to compare execution plans
  - Generating Markdown reports with AI prompts

- **ExplainAnalyzer** (`src/ExplainAnalyzer.php`): Analyzes EXPLAIN results using pattern matching and detectors. Returns detected warnings with documentation links.

- **AIQueryAdvisor** (`src/AIQueryAdvisor.php`): Generates AI prompts containing SQL, schema info, EXPLAIN results, and detected issues. Extracts table names and schema information from the database.

### Detector Pattern

Issue detectors implement `DetectorInterface` (`src/Detector/DetectorInterface.php`):
```php
interface DetectorInterface {
    public function detect(array $explainResult): bool;
}
```

Existing detectors in `src/Detector/`:
- ExcessiveDerivedTablesDetector
- FunctionInvalidatesIndexDetector
- ImplicitTypeConversionDetector
- IneffectiveJoinDetector
- IneffectiveLikePatternDetector
- IneffectiveRangeScanDetector
- IneffectiveSortDetector
- IneffectiveUnionDetector

### Type Definitions

Psalm type definitions are centralized in `src/Types.php`. Import types using:
```php
/** @psalm-import-type ExplainResult from Types */
```

### Reporting

- **QueryStatisticsCalculator**: Calculates statistical metrics across analyzed queries
- **StatisticalQueryLevelClassifier**: Classifies query performance levels using mean and standard deviation
- **MarkdownSummaryReportGenerator**: Generates summary reports comparing all analyzed queries

## Testing

Tests require a MySQL database connection. Test SQL files and parameters are in `tests/sql/` and `tests/params/`.

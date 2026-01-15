# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- CLI tool for analyzing SQL files directly from command line
- `UnnecessaryDistinctDetector` for detecting redundant DISTINCT operations
- `LowCardinalityIndexDetector` for detecting inefficient low-cardinality indexes
- Claude Code Skills for SQL analysis and detector development

### Changed
- Replaced generic array shapes with Psalm domain types for better IDE support and type safety
- Added `#[Override]` attributes to all interface implementations using symfony/polyfill-php83
- Updated PHPCS configuration to use modern sniffs (DNFTypeHintFormat, Generic.WhiteSpace.LanguageConstructSpacing)

### Fixed
- Fixed potential division by zero in `IneffectiveJoinDetector::hasHighRowCount()`
- Fixed potential division by zero in `SqlFileAnalyzer` optimizer comparison calculations
- Fixed type safety in `QueryStatisticsCalculator::calculate()` array_reduce callback

### Removed
- Unused `HIGH_SCAN_THRESHOLD` constant from `LowCardinalityIndexDetector`
- Unused `countInClauseValues()` method from `IneffectiveRangeScanDetector`

## [0.1.7] - 2025-01-14

Previous releases (details to be added)

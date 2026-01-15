# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

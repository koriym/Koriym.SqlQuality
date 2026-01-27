# Koriym.SqlQuality

[![Continuous Integration](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml)
[![Coding Standards](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml)

[Japanese](README.ja.md)

A powerful MySQL query analyzer that helps detect potential performance issues in SQL files and provides AI-powered optimization recommendations.

## Features

- Detects common performance issues (full table scans, inefficient JOINs, etc.)
- Provides AI-powered optimization recommendations
- Supports multiple output languages
- Generates detailed analysis reports in Markdown format

## Requirements

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.2+
- PDO MySQL extension

## Installation

```bash
composer require koriym/sql-quality
```

## Usage

```php
<?php

namespace Koriym\SqlQuality;

use PDO;
use function dirname;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$sqlParams = require 'path/to/sql_params.php';

//return [
//    '1_full_table_scan.sql' => ['min_views' => 1000],
//    '2_filesort.sql' => ['status' => 'published', 'limit' => 10]
//];

$analyzer = new SqlFileAnalyzer(
    $pdo,
    new ExplainAnalyzer(),
    'path/to/sql_dir',
    new AIQueryAdvisor('以上の分析を日本語で記述してください。')
);

// Output to build/sql-quality
$analyzer->analyzeSqlDirectory($sqlParams, __DIR__ . '/build/sql-quality');
```

## Claude Code Skills

For [Claude Code](https://claude.ai/code) users, automated SQL optimization skills are available:

### Installation

**From Marketplace:**
```bash
# Add marketplace
/plugin marketplace add koriym/Koriym.SqlQuality

# Install plugin (includes all 3 skills)
/plugin install sql-quality@sql-quality
```

**For Project Developers:**
When you trust this project folder, Claude Code will automatically prompt you to add the marketplace and enable the plugins (configured in `.claude/settings.json`).

### Usage

```bash
# Analyze SQL files (CI-friendly)
/sql-quality-check tests/sql tests/params/sql_params.php

# Auto-fix issues with step-by-step measurement
/sql-quality-fix tests/sql tests/params/sql_params.php

# Generate parameter bindings from SQL files
/sql-params-generate tests/sql
```

### Features

These AI-powered skills:
- Detect performance issues (FullTableScan, IneffectiveJoin, etc.)
- Rewrite problematic SQL patterns (functions on columns, implicit conversions)
- Create indexes and measure their impact in real-time
- Roll back ineffective indexes automatically
- Generate detailed improvement reports with cost reductions

See `skills/*/SKILL.md` for detailed documentation.

## Analysis Reports

Example:

* [SQL Analysis Summary](demo/build/sql-quality/summary_report.md)

The analyzer generates two types of analysis reports in the specified output directory (e.g., `build/sql-quality`).

### 1. Query Analysis List

Shows the overall analysis of each SQL query:

| Column | Description |
|--------|-------------|
| SQL File | Name of the SQL file |
| Cost | Estimated query cost |
| Level | Performance level based on statistical analysis (μ = mean, σ = standard deviation) |
| Issues | Detected performance issues |
| Report | Link to detailed analysis |

Example:

| SQL File | Cost | Exec Time (ms) | Level | Issues | Report |
|----------|------|----------------|-------|---------|--------|
| 1\_full\_table\_scan.sql | 497.95 | 5.92 | Medium (μ ± σ) | FullTableScan | [Details](1\_full\_table\_scan.md) |

### 2. Queries with Optimizer Impact

The MySQL Query Optimizer is a crucial component that automatically optimizes query execution plans. Even when SQL and index design are not optimal, the optimizer attempts to improve performance at runtime.

| Column | Description |
|--------|-------------|
| SQL File | Name of the SQL file |
| Base Access | Access method, row count, and scan percentage with optimizer disabled |
| Optimized Access | Access method, row count, and scan percentage with optimizer enabled |
| Cost Impact | Cost reduction percentage by optimizer (negative values indicate improvement) |
| Base Issues | Issues detected when optimizer is disabled |
| Plan Changes | Detailed execution plan changes (filtering ratio, cost changes, etc.) |

#### Example Interpretation

Let's look at this example:

| SQL File | Base Access | Optimized Access | Cost Impact | Base Issues | Plan Changes |
|:----------|:------------|:----------------|:------------|:------------|:-------------|
| 11\_nested\_loop.sql | ALL, 4897 rows, 100.0% | ALL, 1000 rows, 10.0% → ref, using idx\_posts\_user\_id, 4 rows, 100.0% | -44.9% | FullTableScan | - |

In this example, without the optimizer, the query performs a full table scan processing 4,897 rows. With the optimizer enabled, it uses an index to access only 4 rows. The cost reduction of -44.9% indicates a significant improvement through optimizer intervention.

### Understanding Optimizer Impact

While the optimizer improves performance, relying on it may mask potential underlying issues. Additionally, there are risks of unstable performance as data volume grows or statistics change. This feature aims to detect such issues early and guide appropriate solutions by comparing execution plans and performance with and without the optimizer.

## Project Statistics

The summary report also includes overall project statistics:

- Total SQL queries analyzed
- Average query cost
- Standard deviation of costs

## Multilingual Support

SQL query analysis results support multilingual output in both `ExplainAnalyzer` and `AIQueryAdvisor`.

### Language Customization in ExplainAnalyzer

While English is the default language, you can customize error messages in `ExplainAnalyzer` constructor for other languages:

```php
// Japanese error messages
$analyzer = new ExplainAnalyzer([
    'FullTableScan' => 'フルテーブルスキャンが検出されました。',
    'IneffectiveJoin' => '非効率的な結合が検出されました。',
    'FunctionInvalidatesIndex' => '関数の使用によりインデックスが無効化されています。',
    // ... other messages
]);

// Combined with AI Advisor for complete Japanese output
$analyzer = new SqlFileAnalyzer(
    $pdo,
    $analyzer,
    $sqlDirectory,
    new AIQueryAdvisor('以上の分析を日本語で記述してください。')
);
```

This allows you to generate the entire analysis report in your preferred language. Both error messages and AI analysis results will be output in the specified language.

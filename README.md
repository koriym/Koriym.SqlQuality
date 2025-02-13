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

The MySQL query optimizer is a crucial component that automatically optimizes query execution plans. This tool analyzes the differences in execution plans and performance with and without the optimizer.

| Column | Description |
|--------|-------------|
| SQL File | Name of the SQL file |
| Base Access | Access method, row count, and scan percentage with optimizer disabled |
| Optimized Access | Access method, row count, and scan percentage with optimizer enabled |
| Cost Impact | Cost reduction percentage by optimizer (negative values indicate improvement) |
| Base Issues | Issues detected when optimizer is disabled |
| Plan Changes | Detailed execution plan changes (filtering ratio, cost changes, etc.) |

#### Example Interpretation

In this example:
```
Base Access: ALL, 4897 rows, 100.0%
Optimized Access: ref, using idx_posts_user_id, 4 rows, 100.0%
Cost Impact: -44.9%
```

In this example, without the optimizer, the query performs a full table scan processing 4,897 rows. With the optimizer enabled, it uses an index to access only 4 rows. The cost reduction of -44.9% indicates a significant improvement through optimizer intervention.

### Understanding Optimizer Impact

A significant cost reduction by the optimizer may indicate potential issues, even if current performance is acceptable. Queries that heavily depend on the optimizer may risk unstable performance as data volume grows or statistics change.

Therefore, queries with high optimizer impact should be reviewed for index design and query pattern improvements. This is an important step in preventing future performance issues and ensuring more stable query execution.

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

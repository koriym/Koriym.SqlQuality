# Koriym.SqlQuality
[![Continuous Integration](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml)
[![Coding Standards](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml)

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

$sqlParams = require dirname(__DIR__) . '/tests/params/sql_params.php';

//return [
//    '1_full_table_scan.sql' => ['min_views' => 1000],
//    '2_filesort.sql' => ['status' => 'published','limit' => 10]
//];

$analyzer = new SqlFileAnalyzer(
    $pdo,
    new ExplainAnalyzer(),
    dirname(__DIR__) . '/tests/sql',
    new AIQueryAdvisor('以上の分析を日本語で記述してください。')
);

// Output to build/sql-quality
$analyzer->analyzeSqlDirectory($sqlParams, __DIR__ . '/build/sql-quality');
```

## Output Format

The analyzer generates a **summary report** and **detailed reports** for each SQL file in the specified output directory (e.g., `build/sql-quality`).

### 1. **Summary Report (`summary_report.md`)**
The summary report provides an overview of all analyzed SQL files, including their cost, severity level, and detected issues.

Example:

* [SQL Analysis Summary](demo/build/sql-quality/summary_report.md)

```markdown
# SQL Analysis Summary

## Query Analysis List
| SQL File | Cost | Level | Issues | Report |
|----------|------|-------|---------|---------|
| 1_full_table_scan.sql | 523.20 | Medium (μ ± σ) | FullTableScan | [Details](1_full_table_scan.md) |
| 2_filesort.sql | 523.20 | Medium (μ ± σ) | FullTableScan, IneffectiveSort | [Details](2_filesort.md) |
| 3_function_on_indexed_column.sql | 523.20 | Medium (μ ± σ) | FullTableScan | [Details](3_function_on_indexed_column.md) |

## Project Statistics
- Total SQLs analyzed: 10
- Average query cost: 724.19
- Standard deviation: 795.91
```

## Multilingual Support

The AI advisor supports multiple languages for its analysis output. You can specify the desired language in the `AIQueryAdvisor` constructor.

```php
// Japanese output
$aiAdvisor = new AIQueryAdvisor('以上の分析を日本語でなるべく記述してください。');

// English output
$aiAdvisor = new AIQueryAdvisor('Please provide the analysis in English');
```

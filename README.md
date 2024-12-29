# Koriym.SqlQuality

A powerful MySQL query analyzer that helps detect potential performance issues in SQL files and provides AI-powered optimization recommendations.

## Features

- Detects common performance issues (full table scans, inefficient JOINs, etc.)
- Provides AI-powered optimization recommendations
- Supports multiple output languages
- Generates detailed analysis reports

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

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use RuntimeException;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$sqlParams = require __DIR__ . '/tests/params/sql_params.php';

$analyzer = new SqlFileAnalyzer(
    $pdo,
    new ExplainAnalyzer(),
    __DIR__ . '/tests/sql',
    new AIQueryAdvisor('以上の分析を日本語で記述してください')
);
$results = $analyzer->analyzeSQLFiles($sqlParams);
echo $analyzer->getFormattedResults($results);
```

## Output Format

The analysis results are provided in two formats:

1. Compact Overview (Console Output)
```
example.sql: Cost=498.45, Issues=[Full table scan]
critical_query.sql: Cost=2949.45 ⚠️, Issues=[Full table scan, Ineffective sort]
```
* Cost: Query execution cost
* ⚠️: Queries requiring special attention
* Issues: Detected performance issues

2. Detailed Report (Markdown)

The analyzer generates detailed Markdown reports in the `ai_prompts` directory under your SQL directory. Each report includes:
* SQL content
* Execution plan details
* Performance analysis
* Optimization suggestions

Example directory structure:
```
/path/to/sql/
├── query1.sql
├── query2.sql
└── ai_prompts/
    ├── query1.md
    └── query2.md
```

## Multilingual Support

The AI advisor supports multiple languages for its analysis output:

```php
// Japanese output
$aiAdvisor = new AIQueryAdvisor('以上の分析を日本語で記述してください');

// English output
$aiAdvisor = new AIQueryAdvisor('Please provide the analysis in English');
```

## License

This project is licensed under the MIT License - see the LICENSE file for details

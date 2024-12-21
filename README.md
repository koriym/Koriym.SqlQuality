# Koriym.SqlQuality

A powerful MySQL query analyzer that helps detect potential performance issues in SQL files and provides AI-powered optimization recommendations. This tool combines traditional SQL analysis with advanced AI suggestions to help you write more efficient queries.

## Features

### Comprehensive SQL Analysis
- Detects common performance issues including:
   - Full table scans and missing indexes
   - Inefficient JOIN operations
   - Index-preventing conditions (functions in WHERE clause)
   - Problematic LIKE patterns with leading wildcards
   - Implicit type conversions
   - Inefficient sorting operations
   - Temporary table usage for grouping

### AI-Powered Optimization
- Provides detailed performance impact analysis
- Generates specific optimization recommendations
- Suggests exact SQL statements for implementing changes
- Evaluates cost-benefit trade-offs for suggested optimizations
- Offers schema optimization suggestions

### Developer-Friendly Output
- Clear, actionable warning messages
- Links to detailed documentation for each issue
- Formatted analysis results with examples
- Support for multiple output languages

## Installation

Install via Composer:

```bash
composer require koriym/sql-quality
```

## Basic Usage

```php
use PDO;
use Koriym\SqlQuality\SqlFileAnalyzer;
use Koriym\SqlQuality\ExplainAnalyzer;
use Koriym\SqlQuality\AIQueryAdvisor;

// Initialize database connection
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'password');

// Create analyzers
$analyzer = new ExplainAnalyzer();
$aiAdvisor = new AIQueryAdvisor();  // Default English output

// Initialize SQL analyzer
$sqlAnalyzer = new SqlFileAnalyzer(
    $pdo,
    $analyzer,
    '/path/to/sql/dir',
    $aiAdvisor
);

// Analyze SQL files with parameters
$results = $sqlAnalyzer->analyzeSQLFiles([
    'query1.sql' => ['user_id' => 1],
    'query2.sql' => ['status' => 'active']
]);

// Output formatted results
echo $sqlAnalyzer->getFormattedResults($results);
```

## Multilingual Support

The AI advisor supports multiple languages for its analysis output:

```php
// Japanese output
$aiAdvisor = new AIQueryAdvisor('以上の分析を日本語で記述してください。');

// French output
$aiAdvisor = new AIQueryAdvisor('Veuillez fournir cette analyse en français.');

// German output
$aiAdvisor = new AIQueryAdvisor('Bitte stellen Sie diese Analyse auf Deutsch bereit.');
```

## Example Output

```text
▶ Query Analysis: query1.sql:

Full table scan detected.
See https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan

Ineffective JOIN operation detected.
See https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveJoin

AI Prompt:
Based on the provided MySQL table schemas and EXPLAIN results, please provide ...
...
```

## CI Integration

Integrate SQL quality checks into your CI pipeline:

```php
$analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), '/path/to/sql');
$results = $analyzer->analyzeSQLFiles($sqlParams);

// Exit with error if issues found
$hasIssues = false;
foreach ($results as $fileResults) {
    if (!empty($fileResults['issues'])) {
        $hasIssues = true;
        break;
    }
}

exit($hasIssues ? 1 : 0);
```

## Documentation

Each detected issue links to detailed documentation explaining:
- What the issue means
- Why it matters
- How to fix it
- Best practices to avoid it

## Requirements

- PHP 8.1+
- MySQL 5.7+ or MariaDB 10.2+
- PDO MySQL extension

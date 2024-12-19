# Koriym.SqlQuality

MySQL query analyzer that detects potential performance issues in SQL files.

## Installation

```bash
composer require koriym/sql-quality
```

## Usage

```php
use PDO;
use Koriym\SqlQuality\SqlFileAnalyzer;
use Koriym\SqlQuality\ExplainAnalyzer;

// Setup database connection
$pdo = new PDO('mysql:host=localhost;dbname=test', 'user', 'password');

// Initialize analyzers
$analyzer = new ExplainAnalyzer();
$sqlAnalyzer = new SqlFileAnalyzer($pdo, $analyzer, '/path/to/sql/dir');

// Analyze SQL files
$results = $sqlAnalyzer->analyzeSQLFiles([
    'query1.sql' => ['user_id' => 1],
    'query2.sql' => ['status' => 'active']
]);

// Get formatted results
echo $sqlAnalyzer->getFormattedResults($results);
```

## What it detects

### Primary Issues

1. Missing or unused indexes
    - Full table scans
    - Available but unused indexes
    - Missing indexes for ORDER BY/GROUP BY

2. Inefficient JOIN operations
    - Hash joins requiring join buffers
    - Missing indexes for JOIN conditions

3. Index-preventing conditions
    - Function calls in WHERE clause
    - Leading wildcard in LIKE queries
    - Non-sargable conditions

## Example Output

```text
Issues found in SQL file: query1.sql
Primary issues:
- full_table_scan: Full table scan detected. Consider adding an appropriate index. (Table: users)
- filesort_required: Extra sorting required for ORDER BY. Add an index that matches the ordering.

Issues found in SQL file: query2.sql
Primary issues:
- inefficient_join: Inefficient JOIN operation using hash join. Add an index for JOIN conditions. (Table: posts)
- inefficient_like: Leading wildcard in LIKE on column(s) title prevents index usage. Consider using full-text search.
```

## CI Integration Example

```php
// In your CI script
$analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), '/path/to/sql');
$results = $analyzer->analyzeSQLFiles($sqlParams);

$hasIssues = false;
foreach ($results as $file => $issues) {
    if (!empty($issues)) {
        $hasIssues = true;
        break;
    }
}

exit($hasIssues ? 1 : 0);
```

## Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.2+

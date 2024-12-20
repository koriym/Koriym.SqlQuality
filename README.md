# Koriym.SqlQuality

MySQL query analyzer that detects potential performance issues in SQL files and provides AI-powered optimization recommendations.

## Installation

```bash
composer require koriym/sql-quality
```

## Usage

### Basic Analysis

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

### AI-Powered Analysis

```php
use Koriym\SqlQuality\AIQueryAdvisor;

// Initialize with AI advisor (optional)
$aiAdvisor = new AIQueryAdvisor('Please provide the analysis in English.');  // or any other language
$sqlAnalyzer = new SqlFileAnalyzer($pdo, $analyzer, '/path/to/sql/dir', $aiAdvisor);

// Get AI-powered optimization suggestions
$results = $sqlAnalyzer->analyzeSQLFiles($sqlParams);
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

### AI-Powered Analysis

When using the AI advisor, you get additional insights:
- Detailed performance impact analysis
- Specific optimization recommendations
- Example SQL for implementing changes
- Cost-benefit analysis of suggested optimizations
- Schema optimization suggestions

## Example Output

### Standard Analysis
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

### With AI Analysis
```text
AI Analysis Suggestions:

Key Performance Issues:
1. Full table scan on 'users' table impacting query performance
2. Inefficient sorting operation due to missing index

Optimization Recommendations:
1. Create compound index for frequently used columns:
   CREATE INDEX idx_users_status_created ON users (status, created_at);

2. Consider query restructuring:
   - Original: SELECT * FROM users WHERE status = ? ORDER BY created_at
   - Optimized: SELECT id, name, email FROM users FORCE INDEX (idx_users_status_created) WHERE status = ?

Expected Benefits:
- Reduced I/O operations
- Elimination of filesort
- Improved query response time
```

## CI Integration Example

```php
// In your CI script
$analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), '/path/to/sql');
$results = $analyzer->analyzeSQLFiles($sqlParams);

$hasIssues = false;
foreach ($results as $file => $fileResults) {
    if (!empty($fileResults['issues'])) {
        $hasIssues = true;
        break;
    }
}

exit($hasIssues ? 1 : 0);
```

## Language Support

The AI advisor supports multiple languages. Simply specify your preferred language when initializing:

```php
// Japanese analysis
$aiAdvisor = new AIQueryAdvisor('以上の分析を日本語で記述してください。');

// French analysis
$aiAdvisor = new AIQueryAdvisor('Veuillez fournir cette analyse en français.');

// German analysis
$aiAdvisor = new AIQueryAdvisor('Bitte stellen Sie diese Analyse auf Deutsch bereit.');
```

## Requirements

- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.2+

## License

This project is licensed under the MIT License - see the LICENSE file for details.

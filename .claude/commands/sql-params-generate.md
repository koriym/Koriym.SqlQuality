# SQL Parameters Generator

Generate parameter bindings for SQL files by analyzing database schema and SQL placeholders.

## Arguments
- `$ARGUMENTS`: SQL directory path (e.g., "tests/sql")

## Prerequisites
- MySQL/MariaDB database must be running with the target schema loaded

## Steps

### 1. Get Schema from Database

Query the database directly for the latest schema:

```sql
-- Get all tables
SHOW TABLES;

-- For each table, get columns
SELECT column_name, data_type, column_type, column_key
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'table_name';

-- Or use SHOW CREATE TABLE for complete DDL
SHOW CREATE TABLE table_name;
```

### 2. Scan SQL Files

For each `.sql` file in the directory:
1. Extract placeholder parameters (`:param_name` or `?` style)
2. Parse SQL to identify referenced tables and columns
3. Match placeholders to column types from schema

### 3. Generate Values by Type

| Column Pattern | Type | Generated Value |
|---------------|------|-----------------|
| `*_id`, `id` | INT | `rand(1, 1000)` |
| `status` | VARCHAR/ENUM | Pick from actual ENUM values or common: 'active', 'pending' |
| `email` | VARCHAR | `'test@example.com'` |
| `name`, `title` | VARCHAR | `'Test Value'` |
| `*_at`, `*_date` | DATETIME/DATE | `'2024-01-01'` |
| `price`, `amount`, `total*` | DECIMAL | `100.00` |
| `count`, `quantity`, `limit` | INT | `10` |
| `is_*`, `has_*` | TINYINT | `1` |
| `content`, `body`, `text` | TEXT | `'Sample text content'` |

### 4. Write Parameter File

Output to `{sql_dir}/../params/sql_params.php`:

```php
<?php

declare(strict_types=1);

return [
    '1_full_table_scan.sql' => ['min_views' => 1000],
    '2_filesort.sql' => ['status' => 'published', 'limit' => 10],
    // SQL files without placeholders use empty array
    '12_select1.sql' => [],
];
```

### 5. Validation

- Warn if placeholder cannot be matched to any column
- Skip files that are DDL-only (CREATE, DROP, ALTER)
- Report any SQL files missing from output

## Database Connection

Use the same connection settings as sql-quality:
- DSN: `mysql:host=127.0.0.1;dbname=test`
- User: `root`
- Password: (empty)

Or read from environment / config file if available.

# SQL Quality Fix

Automatically fix SQL performance issues with step-by-step measurement.

## Arguments
- `$ARGUMENTS`: SQL directory and params file
  - Example: "tests/sql tests/params/sql_params.php"
  - With flag: "tests/sql tests/params/sql_params.php --no-index"

## Options
- `--no-index`: Skip index creation, only suggest DDL

## Steps

### Step 0: Initial Analysis

```bash
php bin/sql-quality analyze \
  --sql-dir="$(echo $ARGUMENTS | cut -d' ' -f1)" \
  --params="$(echo $ARGUMENTS | cut -d' ' -f2)" \
  --format=json
```

Record as baseline.

### Step 1: Fix SQL Files

Apply SQL fixes:

| Issue | Fix |
|-------|-----|
| FullTableScan | Add WHERE with indexed columns |
| FunctionInvalidatesIndex | Rewrite: `YEAR(col)=2024` → `col >= '2024-01-01'` |
| IneffectiveLikePattern | Use prefix match if possible |
| IneffectiveJoin | Reorder JOINs, use explicit syntax |

**Re-analyze and record SQL fix impact.**

### Step 2: Create Indexes (one by one)

For each suggested index:

1. **Create index**
   ```sql
   CREATE INDEX idx_name ON table(columns);
   ```

2. **Re-analyze**

3. **Evaluate impact**
   - Cost improved ≥ 5% → Keep index
   - Cost not improved → Rollback
     ```sql
     DROP INDEX idx_name ON table;
     ```
     Record as "ineffective, rolled back"

### Step 3: Generate Report

Save to `build/sql-quality/fix-result.json`:

```json
{
  "executed_at": "2024-01-15T10:30:00",
  "steps": [
    {
      "step": "initial",
      "total_cost": 650.00
    },
    {
      "step": "sql_fix",
      "total_cost": 450.00,
      "improvement": "-30.8%",
      "changes": [
        {"file": "1_full_table_scan.sql", "change": "Added WHERE user_id = :user_id"}
      ]
    },
    {
      "step": "index",
      "total_cost": 57.30,
      "improvement": "-87.3%",
      "indexes_created": [
        {"ddl": "CREATE INDEX idx_posts_user_id ON posts(user_id)", "impact": "-60%"}
      ],
      "indexes_rolled_back": [
        {"ddl": "CREATE INDEX idx_posts_title ON posts(title)", "reason": "no improvement"}
      ]
    }
  ],
  "final": {
    "total_cost": 57.30,
    "total_improvement": "-91.2%"
  },
  "manual_review_needed": []
}
```

Generate markdown:
```bash
php bin/sql-quality report --input=build/sql-quality/fix-result.json
```

### Output Summary

```
SQL Quality Fix: Complete

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
Step-by-Step Improvement
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
| Step      | Cost   | Change |
|-----------|--------|--------|
| Initial   | 650.00 | -      |
| SQL Fix   | 450.00 | -30.8% |
| Index     | 57.30  | -87.3% |
| **Final** | **57.30** | **-91.2%** |

SQL Changes:
  ✓ 1_full_table_scan.sql: Added WHERE clause

Indexes Created:
  ✓ idx_posts_user_id (-60% cost)

Indexes Rolled Back (ineffective):
  ✗ idx_posts_title (no improvement)

Manual Review:
  (none)

Report: build/sql-quality/fix-report.md
```

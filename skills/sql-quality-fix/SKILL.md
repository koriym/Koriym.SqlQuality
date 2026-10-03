---
name: sql-quality-fix
description: Automatically fix SQL performance issues with step-by-step measurement. Rewrites problematic SQL patterns (functions on columns, implicit conversions), creates indexes, measures their impact, and rolls back ineffective indexes. Reports improvements at each step with cost reduction percentages.
---

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

Exit 2 means the run failed (usage, connection or params file) — stop there.

Total cost is the sum of `queries[].cost`; the report itself carries only
`summary.avg_cost`. Files in `skipped` have no cost and stay out of every
total, so each step compares the same set of queries. Record as baseline.

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

2. **Re-analyze the target file only**
   ```bash
   php bin/sql-quality explain \
     --sql-file="$(echo $ARGUMENTS | cut -d' ' -f1)/<file>" \
     --params="$(echo $ARGUMENTS | cut -d' ' -f2)"
   ```

3. **Evaluate impact**
   - Compare `cost` against the file's cost before this index, and check
     whether the table node under `context.explain.query_block.table` (or
     `nested_loop[*].table`)'s `access_type` / `key` now show the new
     index being used
   - Cost improved ≥ 5% → Keep index
   - Cost not improved → Rollback
     ```sql
     DROP INDEX idx_name ON table;
     ```
     Record as "ineffective, rolled back"

Once every candidate index has been tried, re-run Step 0's `analyze` over the
whole directory once to get the final `total_cost` for Step 3.

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

Write `build/sql-quality/fix-report.md` from that JSON yourself, in the shape
of the summary below. The CLI has no report command.

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

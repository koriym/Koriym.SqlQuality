# SQL Performance Analysis
- **SQL File:** `5_multiple_wildcard_like.sql`
- **Cost:** 497.95

## SQL
```sql
-- Problem: Non-indexed LIKE with leading wildcard
SELECT * FROM posts
WHERE title LIKE :search_word
   OR content LIKE :search_word;

```

## Detected Issues
- Full table scan detected. [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)

## Explain Tree
```
Table scan
+- Table
   table           posts
   rows            4897
   filtered        20.99
   condition       ((`test`.`posts`.`title` like '%keyword%') or (`test`.`posts`.`content` like '%keyword%'))
```

## AI Prompt
Based on the provided MySQL table schemas and EXPLAIN results, please provide:

1. Brief Assessment
   - Summarize key performance bottlenecks identified in the EXPLAIN output
   - Highlight any concerning access patterns (table scans, suboptimal joins)
   - Note any missing or underutilized indexes

2. Specific Optimization Recommendations
   a) Index Improvements
      - New indexes to create (with exact column combinations)
      - Existing indexes to modify or remove
      - Coverage analysis for frequently accessed columns
   b) Query Optimization
      - Join order and method improvements
      - Subquery optimization opportunities
      - Filtering and sorting efficiency
   c) Schema Enhancements (if applicable)
      - Table structure improvements
      - Partitioning considerations
      - Data type optimizations

3. Implementation Details
   For each recommendation:
     - Exact SQL statements for implementation
     - Estimated impact on query performance
     - Potential risks or trade-offs
     - Implementation priority (High/Medium/Low)

4. Additional Considerations
   - Impact on existing indexes and storage requirements
   - Effects on write performance
   - Maintenance requirements
   - Backup/restore implications

Please focus on practical, high-impact improvements that can be implemented with minimal risk.

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":743},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1450},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4897}],"status":{"table_rows":4897,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-11 11:45:35"}}}

### EXPLAIN Results
{"query_block":{"select_id":1,"cost_info":{"query_cost":"497.95"},"table":{"table_name":"posts","access_type":"ALL","rows_examined_per_scan":4897,"rows_produced_per_join":1027,"filtered":"20.99","cost_info":{"read_cost":"395.18","eval_cost":"102.77","prefix_cost":"497.95","data_read_per_join":"1M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"],"attached_condition":"((`test`.`posts`.`title` like '%keyword%') or (`test`.`posts`.`content` like '%keyword%'))"}}}

以上の分析を日本語で記述してください。
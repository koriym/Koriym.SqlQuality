# SQL Performance Analysis
- **SQL File:** `10_redundant_join.sql`
- **Cost:** 101.75

## SQL
```sql
-- Problem: Unnecessary join when a subquery would be more efficient
SELECT u.*,
       (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count,
       (SELECT COUNT(*) FROM comments WHERE user_id = u.id) as comment_count
FROM users u
WHERE u.status = :status;

```

## Detected Issues
- Full table scan detected. [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)

## Explain Tree
```
Table scan
+- Table
|  table           u
|  rows            1000
|  filtered        10.00
|  condition       (`test`.`u`.`status` = 'active')
+- Subquery (comments)
|  access_type     ref
|  key             user_id
|  rows            9
|  filtered        100.00
|  using_index     true
+- Subquery (orders)
   access_type     ref
   key             idx_orders_user_id
   rows            2
   filtered        100.00
   using_index     true
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
{"orders":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"total_amount","DATA_TYPE":"decimal","COLUMN_TYPE":"decimal(10,2)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"reference_code","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(50)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_orders_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":869},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":2000}],"status":{"table_rows":2000,"data_length":163840,"index_length":49152,"auto_increment":null,"create_time":"2025-02-07 08:57:29","update_time":"2025-02-07 08:57:30"}},"comments":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"post_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_comments_post_id","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4319},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":9668},{"INDEX_NAME":"user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":9668,"data_length":1589248,"index_length":540672,"auto_increment":null,"create_time":"2025-02-07 08:57:29","update_time":"2025-02-07 08:57:30"}},"users":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"name","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"email","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"updated_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_users_id","COLUMN_NAME":"id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":1000,"data_length":114688,"index_length":16384,"auto_increment":null,"create_time":"2025-02-07 08:57:29","update_time":"2025-02-07 08:57:29"}}}

### EXPLAIN Results
{"query_block":{"select_id":1,"cost_info":{"query_cost":"101.75"},"table":{"table_name":"u","access_type":"ALL","rows_examined_per_scan":1000,"rows_produced_per_join":100,"filtered":"10.00","cost_info":{"read_cost":"91.75","eval_cost":"10.00","prefix_cost":"101.75","data_read_per_join":"209K"},"used_columns":["id","name","email","status","created_at","updated_at"],"attached_condition":"(`test`.`u`.`status` = 'active')"},"select_list_subqueries":[{"dependent":true,"cacheable":false,"query_block":{"select_id":3,"cost_info":{"query_cost":"1.22"},"table":{"table_name":"comments","access_type":"ref","possible_keys":["user_id"],"key":"user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.u.id"],"rows_examined_per_scan":9,"rows_produced_per_join":9,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"0.25","eval_cost":"0.97","prefix_cost":"1.22","data_read_per_join":"309"},"used_columns":["user_id"]}}},{"dependent":true,"cacheable":false,"query_block":{"select_id":2,"cost_info":{"query_cost":"0.48"},"table":{"table_name":"orders","access_type":"ref","possible_keys":["idx_orders_user_id"],"key":"idx_orders_user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.u.id"],"rows_examined_per_scan":2,"rows_produced_per_join":2,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"0.25","eval_cost":"0.23","prefix_cost":"0.48","data_read_per_join":"699"},"used_columns":["user_id"]}}}]}}

以上の分析を日本語で記述してください。
# SQL Performance Analysis
- **SQL File:** `21_low_cardinality_index.sql`
- **Cost:** 85.25

## SQL
```sql
-- Problem: Index on low cardinality column causing inefficient scans
SELECT * FROM users
WHERE status = 'active'
ORDER BY created_at;

```

## Detected Issues


## Explain Tree
```
Sort
+- Table scan
   +- Table
      table           users
      rows            800
      filtered        100.00
```
## Analysis Detail

### Schema
{"users":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"name","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"email","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"active","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"updated_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED on update CURRENT_TIMESTAMP"}],"indexes":[{"INDEX_NAME":"idx_users_id","COLUMN_NAME":"id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":495},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":1000,"data_length":114688,"index_length":65536,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"85.25"},"ordering_operation":{"using_filesort":false,"table":{"table_name":"users","access_type":"ref","possible_keys":["idx_users_status_created"],"key":"idx_users_status_created","used_key_parts":["status"],"key_length":"83","ref":["const"],"rows_examined_per_scan":800,"rows_produced_per_join":800,"filtered":"100.00","cost_info":{"read_cost":"5.25","eval_cost":"80.00","prefix_cost":"85.25","data_read_per_join":"1M"},"used_columns":["id","name","email","status","created_at","updated_at"]}}}

### EXPLAIN ANALYZE
-> Index lookup on users using idx_users_status_created (status='active')  (cost=85.25 rows=800) (actual time=0.068..0.971 rows=800 loops=1)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
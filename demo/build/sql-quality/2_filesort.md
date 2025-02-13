# SQL Performance Analysis
- **SQL File:** `2_filesort.sql`
- **Cost:** 269.55

## SQL
```sql
-- Problem: No index on created_at for sorting
SELECT * FROM posts
WHERE status = :status
ORDER BY created_at DESC
LIMIT :limit;

```

## Detected Issues
- 非効率的なソート操作が検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveSort)

## Explain Tree
```
Sort
+- Table scan
   +- Table
      table           posts
      rows            2448
      filtered        100.00
```
## Analysis Detail

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":743},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1450},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4897}],"status":{"table_rows":4897,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-12 05:40:33"}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"269.55"},"ordering_operation":{"using_filesort":false,"table":{"table_name":"posts","access_type":"ref","possible_keys":["idx_posts_status_created"],"key":"idx_posts_status_created","used_key_parts":["status"],"key_length":"83","ref":["const"],"rows_examined_per_scan":2448,"rows_produced_per_join":2448,"filtered":"100.00","backward_index_scan":true,"cost_info":{"read_cost":"24.75","eval_cost":"244.80","prefix_cost":"269.55","data_read_per_join":"2M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"]}}}

### EXPLAIN ANALYZE
-> Limit: 10 row(s)  (cost=270 rows=10) (actual time=0.003..0.00726 rows=10 loops=1)
    -> Index lookup on posts using idx_posts_status_created (status='published') (reverse)  (cost=270 rows=2448) (actual time=0.00275..0.00667 rows=10 loops=1)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
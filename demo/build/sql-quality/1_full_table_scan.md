# SQL Performance Analysis
- **SQL File:** `1_full_table_scan.sql`
- **Cost:** 497.95

## SQL
```sql
-- Problem: No index on view_count column
SELECT * FROM posts
WHERE view_count > :min_views;

```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)

## Explain Tree
```
Table scan
+- Table
   table           posts
   rows            4897
   filtered        33.33
   condition       (`test`.`posts`.`view_count` > 1000)
```
## Analysis Detail

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":743},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1450},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4897}],"status":{"table_rows":4897,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-12 05:40:33"}}}

### EXPLAIN JSON
{"query_block":{"select_id":1,"cost_info":{"query_cost":"497.95"},"table":{"table_name":"posts","access_type":"ALL","rows_examined_per_scan":4897,"rows_produced_per_join":1632,"filtered":"33.33","cost_info":{"read_cost":"334.73","eval_cost":"163.22","prefix_cost":"497.95","data_read_per_join":"1M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"],"attached_condition":"(`test`.`posts`.`view_count` > 1000)"}},"analyze_result":{"EXPLAIN":"-> Filter: (posts.view_count > 1000)  (cost=498 rows=1632) (actual time=0.00321..2.24 rows=4497 loops=1)\n    -> Table scan on posts  (cost=498 rows=4897) (actual time=0.00283..1.94 rows=5000 loops=1)\n"}}

### EXPLAIN ANALYZE
{"EXPLAIN":"-> Filter: (posts.view_count > 1000)  (cost=498 rows=1632) (actual time=0.00321..2.24 rows=4497 loops=1)\n    -> Table scan on posts  (cost=498 rows=4897) (actual time=0.00283..1.94 rows=5000 loops=1)\n"}

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
# SQL Performance Analysis
- **SQL File:** `8_suboptimal_or_condition.sql`
- **Cost:** 503.85

## SQL
```sql
-- 8_suboptimal_or_condition.sql
-- Problem: OR condition preventing index usage
SELECT * FROM posts
WHERE user_id = :user_id
   OR status = 'published';


```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)
- 暗黙的な型変換が検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/ImplicitTypeConversion)

## Explain Tree
```
Table scan
+- Table
   table           posts
   rows            4956
   filtered        33.40
   condition       ((`test`.`posts`.`user_id` = 1) or (`test`.`posts`.`status` = 'published'))
```
## Analysis Detail

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1617},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":992},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":992},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1439},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4956}],"status":{"table_rows":4956,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"503.85"},"table":{"table_name":"posts","access_type":"ALL","possible_keys":["idx_posts_user_id","idx_posts_status_created","idx_posts_user_status"],"rows_examined_per_scan":4956,"rows_produced_per_join":1655,"filtered":"33.40","cost_info":{"read_cost":"338.32","eval_cost":"165.53","prefix_cost":"503.85","data_read_per_join":"1M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"],"attached_condition":"((`test`.`posts`.`user_id` = 1) or (`test`.`posts`.`status` = 'published'))"}}

### EXPLAIN ANALYZE
-> Filter: ((posts.user_id = 1) or (posts.`status` = 'published'))  (cost=503.85 rows=1655) (actual time=0.012..3.656 rows=4500 loops=1)
    -> Table scan on posts  (cost=503.85 rows=4956) (actual time=0.009..2.458 rows=5000 loops=1)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
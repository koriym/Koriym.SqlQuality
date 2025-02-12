# SQL Performance Analysis
- **SQL File:** `9_inefficient_in_query.sql`
- **Cost:** 3447.95

## SQL
```sql
-- 9_inefficient_in_query.sql
-- Problem: Large IN clause without proper indexing
SELECT * FROM posts
WHERE status IN (:status1, :status2, :status3, :status4, :status5)
ORDER BY created_at;


```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)
- 非効率的な範囲スキャンが検出されました。範囲条件が多すぎる行をカバーしています。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveRangeScan)
- 非効率的なソート操作が検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveSort)

## Explain Tree
```
Sort (using filesort)
sort_cost       2950.00
+- Filter with IN condition
   +- Table scan
      +- Table
         table           posts
         rows            4897
         filtered        60.24
         condition       (`test`.`posts`.`status` in ('draft','published','archived','deleted','pending'))
```
## Analysis Detail

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":743},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1450},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4897}],"status":{"table_rows":4897,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-12 05:40:33"}}}

### EXPLAIN JSON
{"query_block":{"select_id":1,"cost_info":{"query_cost":"3447.95"},"ordering_operation":{"using_filesort":true,"cost_info":{"sort_cost":"2950.00"},"table":{"table_name":"posts","access_type":"ALL","possible_keys":["idx_posts_status_created"],"rows_examined_per_scan":4897,"rows_produced_per_join":2949,"filtered":"60.24","cost_info":{"read_cost":"202.95","eval_cost":"295.00","prefix_cost":"497.95","data_read_per_join":"3M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"],"attached_condition":"(`test`.`posts`.`status` in ('draft','published','archived','deleted','pending'))"}}},"analyze_result":{"EXPLAIN":"-> Sort: posts.created_at  (cost=498 rows=4897) (actual time=3.17..3.61 rows=5000 loops=1)\n    -> Filter: (posts.`status` in ('draft','published','archived','deleted','pending'))  (cost=498 rows=4897) (actual time=0.00425..1.84 rows=5000 loops=1)\n        -> Table scan on posts  (cost=498 rows=4897) (actual time=0.00363..1.07 rows=5000 loops=1)\n"}}

### EXPLAIN ANALYZE
{"EXPLAIN":"-> Sort: posts.created_at  (cost=498 rows=4897) (actual time=3.17..3.61 rows=5000 loops=1)\n    -> Filter: (posts.`status` in ('draft','published','archived','deleted','pending'))  (cost=498 rows=4897) (actual time=0.00425..1.84 rows=5000 loops=1)\n        -> Table scan on posts  (cost=498 rows=4897) (actual time=0.00363..1.07 rows=5000 loops=1)\n"}

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。

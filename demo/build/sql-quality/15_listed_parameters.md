# SQL Performance Analysis
- **SQL File:** `15_listed_parameters.sql`
- **Cost:** 101.75

## SQL
```sql
-- 15_listed_paramaters.sql
-- Problem: Listed parameters were not passed
SELECT * FROM users WHERE users.name IN (:listed_params);

```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)
- 非効率的な範囲スキャンが検出されました。範囲条件が多すぎる行をカバーしています。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveRangeScan)

## Explain Tree
```
Table scan
+- Table
   table           users
   rows            1000
   filtered        20.00
   condition       (`test`.`users`.`name` in ('User 1','User 10'))
```
## Analysis Detail

### Schema
{"users":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"name","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"email","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"active","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"updated_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED on update CURRENT_TIMESTAMP"}],"indexes":[{"INDEX_NAME":"idx_users_id","COLUMN_NAME":"id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":642},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":1000,"data_length":114688,"index_length":65536,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-11 11:45:35"}}}

### EXPLAIN JSON
{"query_block":{"select_id":1,"cost_info":{"query_cost":"101.75"},"table":{"table_name":"users","access_type":"ALL","rows_examined_per_scan":1000,"rows_produced_per_join":200,"filtered":"20.00","cost_info":{"read_cost":"81.75","eval_cost":"20.00","prefix_cost":"101.75","data_read_per_join":"418K"},"used_columns":["id","name","email","status","created_at","updated_at"],"attached_condition":"(`test`.`users`.`name` in ('User 1','User 10'))"}},"analyze_result":{"EXPLAIN":"-> Filter: (users.`name` in ('User 1','User 10'))  (cost=102 rows=200) (actual time=0.00846..0.242 rows=2 loops=1)\n    -> Table scan on users  (cost=102 rows=1000) (actual time=0.00812..0.193 rows=1000 loops=1)\n"}}

### EXPLAIN ANALYZE
{"EXPLAIN":"-> Filter: (users.`name` in ('User 1','User 10'))  (cost=102 rows=200) (actual time=0.00846..0.242 rows=2 loops=1)\n    -> Table scan on users  (cost=102 rows=1000) (actual time=0.00812..0.193 rows=1000 loops=1)\n"}

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
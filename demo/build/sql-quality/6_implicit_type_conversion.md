# SQL Performance Analysis
- **SQL File:** `6_implicit_type_conversion.sql`
- **Cost:** 202.50

## SQL
```sql
-- 6_implicit_type_conversion.sql
-- Problem: Implicit type conversion due to VARCHAR comparison with INT
SELECT * FROM orders
WHERE reference_code = 12345;

```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)
- 暗黙的な型変換が検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/ImplicitTypeConversion)
- 非効率的な範囲スキャンが検出されました。範囲条件が多すぎる行をカバーしています。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveRangeScan)

## Explain Tree
```
Table scan
+- Table
   table           orders
   rows            2000
   filtered        10.00
   condition       (`test`.`orders`.`reference_code` = 12345)
```
## Analysis Detail

### Schema
{"orders":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"total_amount","DATA_TYPE":"decimal","COLUMN_TYPE":"decimal(10,2)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"reference_code","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(50)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":440},{"INDEX_NAME":"idx_orders_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":862},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":862},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":929},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":2000}],"status":{"table_rows":2000,"data_length":163840,"index_length":212992,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-11 11:45:36"}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"202.50"},"table":{"table_name":"orders","access_type":"ALL","rows_examined_per_scan":2000,"rows_produced_per_join":200,"filtered":"10.00","cost_info":{"read_cost":"182.50","eval_cost":"20.00","prefix_cost":"202.50","data_read_per_join":"59K"},"used_columns":["id","user_id","total_amount","status","created_at","reference_code"],"attached_condition":"(`test`.`orders`.`reference_code` = 12345)"}}

### EXPLAIN ANALYZE
-> Filter: (orders.reference_code = 12345)  (cost=202 rows=200) (actual time=0.574..0.574 rows=0 loops=1)
    -> Table scan on orders  (cost=202 rows=2000) (actual time=0.0221..0.317 rows=2000 loops=1)

### SHOW WARNINGS
[{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000001'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000002'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000003'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000004'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000005'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000006'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000007'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000008'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000009'"},{"Level":"Warning","Code":1292,"Message":"Truncated incorrect DOUBLE value: 'REF000010'"}]

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
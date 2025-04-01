# SQL Performance Analysis
- **SQL File:** `16_ineffective_range_scan.sql`
- **Cost:** 424.70

## SQL
```sql
-- Problem: Wide range scan causing excessive row examination
SELECT * FROM orders
WHERE total_amount BETWEEN 1 AND 1000
ORDER BY created_at;

```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)
- 非効率的な範囲スキャンが検出されました。範囲条件が多すぎる行をカバーしています。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveRangeScan)
- 非効率的なソート操作が検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveSort)

## Explain Tree
```
Sort (using filesort)
sort_cost       222.20
+- Table scan
   +- Table
      table           orders
      rows            2000
      filtered        11.11
      condition       (`test`.`orders`.`total_amount` between 1 and 1000)
```
## Analysis Detail

### Schema
{"orders":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"total_amount","DATA_TYPE":"decimal","COLUMN_TYPE":"decimal(10,2)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"reference_code","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(50)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":755},{"INDEX_NAME":"idx_orders_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":861},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":861},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":924},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":2000}],"status":{"table_rows":2000,"data_length":163840,"index_length":212992,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"424.70"},"ordering_operation":{"using_filesort":true,"cost_info":{"sort_cost":"222.20"},"table":{"table_name":"orders","access_type":"ALL","rows_examined_per_scan":2000,"rows_produced_per_join":222,"filtered":"11.11","cost_info":{"read_cost":"180.28","eval_cost":"22.22","prefix_cost":"202.50","data_read_per_join":"65K"},"used_columns":["id","user_id","total_amount","status","created_at","reference_code"],"attached_condition":"(`test`.`orders`.`total_amount` between 1 and 1000)"}}}

### EXPLAIN ANALYZE
-> Sort: orders.created_at  (cost=202.50 rows=2000) (actual time=1.507..1.698 rows=1995 loops=1)
    -> Filter: (orders.total_amount between 1 and 1000)  (cost=202.50 rows=2000) (actual time=0.028..0.947 rows=1995 loops=1)
        -> Table scan on orders  (cost=202.50 rows=2000) (actual time=0.027..0.602 rows=2000 loops=1)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
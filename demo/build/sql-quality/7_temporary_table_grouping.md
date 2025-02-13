# SQL Performance Analysis
- **SQL File:** `7_temporary_table_grouping.sql`
- **Cost:** 202.50

## SQL
```sql
-- 7_temporary_table_grouping.sql
-- Problem: Requires temporary table for grouping with ORDER BY
SELECT user_id, COUNT(*) as order_count
FROM orders
GROUP BY user_id
ORDER BY order_count DESC;

```

## Detected Issues
- グループ化のために一時テーブルが必要です。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/TemporaryTableGrouping)

## Explain Tree
```
Group and Sort
using_temporary_table true
using_filesort  true
```
## Analysis Detail

### Schema
{"orders":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"total_amount","DATA_TYPE":"decimal","COLUMN_TYPE":"decimal(10,2)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"reference_code","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(50)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":440},{"INDEX_NAME":"idx_orders_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":862},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":862},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":929},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":2000}],"status":{"table_rows":2000,"data_length":163840,"index_length":212992,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-11 11:45:36"}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"202.50"},"ordering_operation":{"using_temporary_table":true,"using_filesort":true,"grouping_operation":{"using_filesort":false,"table":{"table_name":"orders","access_type":"index","possible_keys":["idx_orders_user_id","idx_orders_user_status"],"key":"idx_orders_user_id","used_key_parts":["user_id"],"key_length":"5","rows_examined_per_scan":2000,"rows_produced_per_join":2000,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"2.50","eval_cost":"200.00","prefix_cost":"202.50","data_read_per_join":"593K"},"used_columns":["id","user_id"]}}}}

### EXPLAIN ANALYZE
-> Sort: order_count DESC  (actual time=0.318..0.332 rows=862 loops=1)
    -> Stream results  (cost=402 rows=862) (actual time=0.0226..0.246 rows=862 loops=1)
        -> Group aggregate: count(0)  (cost=402 rows=862) (actual time=0.0223..0.21 rows=862 loops=1)
            -> Covering index scan on orders using idx_orders_user_id  (cost=202 rows=2000) (actual time=0.022..0.152 rows=2000 loops=1)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
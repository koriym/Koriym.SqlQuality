# SQL Performance Analysis
- **SQL File:** `10_redundant_join.sql`
- **Cost:** 85.25

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


## Explain Tree
```
Table scan
+- Table
|  table           u
|  rows            800
|  filtered        100.00
+- Subquery (comments)
|  access_type     ref
|  key             user_id
|  rows            10
|  filtered        100.00
|  using_index     true
+- Subquery (orders)
   access_type     ref
   key             idx_orders_user_id
   rows            2
   filtered        100.00
   using_index     true
```
## Analysis Detail

### Schema
{"orders":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"total_amount","DATA_TYPE":"decimal","COLUMN_TYPE":"decimal(10,2)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"reference_code","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(50)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""}],"indexes":[{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_orders_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":440},{"INDEX_NAME":"idx_orders_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":862},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":862},{"INDEX_NAME":"idx_orders_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":929},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":2000}],"status":{"table_rows":2000,"data_length":163840,"index_length":212992,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-11 11:45:36"}},"comments":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"post_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4338},{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":9980},{"INDEX_NAME":"idx_comments_post_id","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4338},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":10284},{"INDEX_NAME":"user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":10284,"data_length":1589248,"index_length":835584,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-12 05:40:33"}},"users":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"name","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"email","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"active","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"updated_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED on update CURRENT_TIMESTAMP"}],"indexes":[{"INDEX_NAME":"idx_users_id","COLUMN_NAME":"id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":642},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":1000,"data_length":114688,"index_length":65536,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-11 11:45:35"}}}

### EXPLAIN JSON
{"query_block":{"select_id":1,"cost_info":{"query_cost":"85.25"},"table":{"table_name":"u","access_type":"ref","possible_keys":["idx_users_status_created"],"key":"idx_users_status_created","used_key_parts":["status"],"key_length":"83","ref":["const"],"rows_examined_per_scan":800,"rows_produced_per_join":800,"filtered":"100.00","cost_info":{"read_cost":"5.25","eval_cost":"80.00","prefix_cost":"85.25","data_read_per_join":"1M"},"used_columns":["id","name","email","status","created_at","updated_at"]},"select_list_subqueries":[{"dependent":true,"cacheable":false,"query_block":{"select_id":3,"cost_info":{"query_cost":"1.28"},"table":{"table_name":"comments","access_type":"ref","possible_keys":["user_id"],"key":"user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.u.id"],"rows_examined_per_scan":10,"rows_produced_per_join":10,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"0.25","eval_cost":"1.03","prefix_cost":"1.28","data_read_per_join":"1K"},"used_columns":["user_id"]}}},{"dependent":true,"cacheable":false,"query_block":{"select_id":2,"cost_info":{"query_cost":"0.48"},"table":{"table_name":"orders","access_type":"ref","possible_keys":["idx_orders_user_id","idx_orders_user_status"],"key":"idx_orders_user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.u.id"],"rows_examined_per_scan":2,"rows_produced_per_join":2,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"0.25","eval_cost":"0.23","prefix_cost":"0.48","data_read_per_join":"705"},"used_columns":["user_id"]}}}]},"analyze_result":{"EXPLAIN":"-> Index lookup on u using idx_users_status_created (status='active')  (cost=85.2 rows=800) (actual time=0.0257..0.324 rows=800 loops=1)\n-> Select #2 (subquery in projection; dependent)\n    -> Aggregate: count(0)  (cost=0.714 rows=1) (actual time=908e-6..924e-6 rows=1 loops=800)\n        -> Covering index lookup on orders using idx_orders_user_id (user_id=u.id)  (cost=0.482 rows=2.32) (actual time=640e-6..790e-6 rows=2.01 loops=800)\n-> Select #3 (subquery in projection; dependent)\n    -> Aggregate: count(0)  (cost=2.31 rows=1) (actual time=0.00153..0.00155 rows=1 loops=800)\n        -> Covering index lookup on comments using user_id (user_id=u.id)  (cost=1.28 rows=10.3) (actual time=812e-6..0.00125 rows=9.99 loops=800)\n"}}

### EXPLAIN ANALYZE
{"EXPLAIN":"-> Index lookup on u using idx_users_status_created (status='active')  (cost=85.2 rows=800) (actual time=0.0257..0.324 rows=800 loops=1)\n-> Select #2 (subquery in projection; dependent)\n    -> Aggregate: count(0)  (cost=0.714 rows=1) (actual time=908e-6..924e-6 rows=1 loops=800)\n        -> Covering index lookup on orders using idx_orders_user_id (user_id=u.id)  (cost=0.482 rows=2.32) (actual time=640e-6..790e-6 rows=2.01 loops=800)\n-> Select #3 (subquery in projection; dependent)\n    -> Aggregate: count(0)  (cost=2.31 rows=1) (actual time=0.00153..0.00155 rows=1 loops=800)\n        -> Covering index lookup on comments using user_id (user_id=u.id)  (cost=1.28 rows=10.3) (actual time=812e-6..0.00125 rows=9.99 loops=800)\n"}

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
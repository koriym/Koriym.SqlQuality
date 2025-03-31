# SQL Performance Analysis
- **SQL File:** `25_pass_the_with_clause.sql`
- **Cost:** 2888.93

## SQL
```sql
-- 25_pass_the_with_clause.sql
WITH active_users AS (
	SELECT
    id
  FROM
    users
  WHERE
    status = 'active'
)
SELECT
  *
FROM
  comments
WHERE
  user_id
IN (
  SELECT
    id
  FROM
    active_users
)

```

## Detected Issues


## Explain Tree
```
JOIN
+- Index lookup
|  key             idx_users_status_created
|  rows            800
|  filtered        100.00
|  +- Table
|     table           users
+- Index lookup
   key             user_id
   rows            10
   filtered        100.00
   +- Table
      table           comments
```
## Analysis Detail

### Schema
{"users":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"name","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"email","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"active","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"updated_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED on update CURRENT_TIMESTAMP"}],"indexes":[{"INDEX_NAME":"idx_users_id","COLUMN_NAME":"id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":495},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":1000,"data_length":114688,"index_length":65536,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}},"comments":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"post_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4318},{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":9995},{"INDEX_NAME":"idx_comments_post_id","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4318},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":10023},{"INDEX_NAME":"user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":10023,"data_length":1589248,"index_length":835584,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}},"active_users":{"columns":[],"indexes":[],"status":{"table_rows":null,"data_length":null,"index_length":null,"auto_increment":null,"create_time":null,"update_time":null}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"2888.93"},"nested_loop":[{"table":{"table_name":"users","access_type":"ref","possible_keys":["PRIMARY","idx_users_id","idx_users_status_created"],"key":"idx_users_status_created","used_key_parts":["status"],"key_length":"83","ref":["const"],"rows_examined_per_scan":800,"rows_produced_per_join":800,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"2.49","eval_cost":"80.00","prefix_cost":"82.49","data_read_per_join":"1M"},"used_columns":["id","status"]}},{"table":{"table_name":"comments","access_type":"ref","possible_keys":["user_id"],"key":"user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.users.id"],"rows_examined_per_scan":10,"rows_produced_per_join":8018,"filtered":"100.00","cost_info":{"read_cost":"2004.60","eval_cost":"801.84","prefix_cost":"2888.93","data_read_per_join":"877K"},"used_columns":["id","post_id","user_id","content","status","created_at"]}}]}

### EXPLAIN ANALYZE
-> Nested loop inner join  (cost=2888.93 rows=8018) (actual time=0.031..8.847 rows=8011 loops=1)
    -> Index lookup on users using idx_users_status_created (status='active')  (cost=82.49 rows=800) (actual time=0.022..0.281 rows=800 loops=1)
    -> Index lookup on comments using user_id (user_id=users.id)  (cost=2.51 rows=10) (actual time=0.001..0.010 rows=10 loops=800)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
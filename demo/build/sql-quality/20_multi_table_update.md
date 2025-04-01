# SQL Performance Analysis
- **SQL File:** `20_multi_table_update.sql`
- **Cost:** 2195.78

## SQL
```sql
-- Problem: Multiple table update requiring table locks
UPDATE posts p
    JOIN comments c ON p.id = c.post_id
SET p.view_count = p.view_count + 1,
    c.content = CONCAT(c.content, ' [Updated]')
WHERE p.status = 'published'
  AND c.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY);

```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)

## Explain Tree
```
JOIN
+- Table scan
   rows            10023
   +- Table
      table           c
      possible_keys   idx_comments_post_id, idx_comments_post_created
      condition       ((`test`.`c`.`created_at` > <cache>((now() - interval 1 day))) and (`test`.`c`.`post_id` is not null))
```
## Analysis Detail

### Schema
{"comments":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"post_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4318},{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":9995},{"INDEX_NAME":"idx_comments_post_id","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4318},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":10023},{"INDEX_NAME":"user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":10023,"data_length":1589248,"index_length":835584,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"2195.78"},"nested_loop":[{"table":{"update":true,"table_name":"c","access_type":"ALL","possible_keys":["idx_comments_post_id","idx_comments_post_created"],"rows_examined_per_scan":10023,"rows_produced_per_join":3340,"filtered":"33.33","cost_info":{"read_cost":"24.25","eval_cost":"334.07","prefix_cost":"1026.55","data_read_per_join":"365K"},"used_columns":["id","post_id","user_id","content","status","created_at"],"attached_condition":"((`test`.`c`.`created_at` > <cache>((now() - interval 1 day))) and (`test`.`c`.`post_id` is not null))"}},{"table":{"update":true,"table_name":"p","access_type":"eq_ref","possible_keys":["PRIMARY","idx_posts_status_created"],"key":"PRIMARY","used_key_parts":["id"],"key_length":"4","ref":["test.c.post_id"],"rows_examined_per_scan":1,"rows_produced_per_join":1670,"filtered":"50.00","cost_info":{"read_cost":"835.17","eval_cost":"167.03","prefix_cost":"2195.78","data_read_per_join":"1M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"],"attached_condition":"(`test`.`p`.`status` = 'published')"}}]}

### EXPLAIN ANALYZE
-> Update c (immediate), p (buffered)
    -> Nested loop inner join  (cost=1527.55 rows=1670) (actual time=7.057..7.057 rows=0 loops=1)
        -> Filter: ((c.created_at > <cache>((now() - interval 1 day))) and (c.post_id is not null))  (cost=358.32 rows=3341) (actual time=7.057..7.057 rows=0 loops=1)
            -> Table scan on c  (cost=358.32 rows=10023) (actual time=0.010..5.949 rows=10000 loops=1)
        -> Filter: (p.`status` = 'published')  (cost=0.25 rows=0) (never executed)
            -> Single-row index lookup on p using PRIMARY (id=c.post_id)  (cost=0.25 rows=1) (never executed)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
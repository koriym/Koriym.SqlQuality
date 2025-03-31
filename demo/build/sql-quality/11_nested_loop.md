# SQL Performance Analysis
- **SQL File:** `11_nested_loop.sql`
- **Cost:** 276.61

## SQL
```sql
-- 11_nested_loop.sql
SELECT `id`, `title`, `content`
FROM `posts`
WHERE EXISTS(SELECT 1
             FROM `users`
             WHERE `users`.`id` = `posts`.`user_id`
               AND `users`.`email` = :email);

```

## Detected Issues
- フルテーブルスキャンが検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/FullTableScan)
- 非効率的な範囲スキャンが検出されました。範囲条件が多すぎる行をカバーしています。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveRangeScan)

## Explain Tree
```
JOIN
+- Table scan
|  rows            1000
|  +- Table
|     table           users
|     possible_keys   PRIMARY, idx_users_id
|     condition       (`test`.`users`.`email` = 'example@example.com')
+- Index lookup
   key             idx_posts_user_id
   rows            4
   filtered        100.00
   +- Table
      table           posts
```
## Analysis Detail

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1617},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":992},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":992},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1439},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4956}],"status":{"table_rows":4956,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}},"users":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"name","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"email","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"active","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"},{"COLUMN_NAME":"updated_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED on update CURRENT_TIMESTAMP"}],"indexes":[{"INDEX_NAME":"idx_users_id","COLUMN_NAME":"id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_users_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":495},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":1000,"data_length":114688,"index_length":65536,"auto_increment":null,"create_time":"2025-02-13 10:11:38","update_time":null}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"276.61"},"nested_loop":[{"table":{"table_name":"users","access_type":"ALL","possible_keys":["PRIMARY","idx_users_id"],"rows_examined_per_scan":1000,"rows_produced_per_join":100,"filtered":"10.00","cost_info":{"read_cost":"91.75","eval_cost":"10.00","prefix_cost":"101.75","data_read_per_join":"209K"},"used_columns":["id","email"],"attached_condition":"(`test`.`users`.`email` = 'example@example.com')"}},{"table":{"table_name":"posts","access_type":"ref","possible_keys":["idx_posts_user_id","idx_posts_user_status"],"key":"idx_posts_user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.users.id"],"rows_examined_per_scan":4,"rows_produced_per_join":499,"filtered":"100.00","cost_info":{"read_cost":"124.90","eval_cost":"49.96","prefix_cost":"276.61","data_read_per_join":"554K"},"used_columns":["id","user_id","title","content"]}}]}

### EXPLAIN ANALYZE
-> Nested loop inner join  (cost=276.61 rows=500) (actual time=0.325..0.325 rows=0 loops=1)
    -> Filter: (users.email = 'example@example.com')  (cost=101.75 rows=100) (actual time=0.325..0.325 rows=0 loops=1)
        -> Table scan on users  (cost=101.75 rows=1000) (actual time=0.018..0.243 rows=1000 loops=1)
    -> Index lookup on posts using idx_posts_user_id (user_id=users.id)  (cost=1.25 rows=5) (never executed)

### SHOW WARNINGS
[{"Level":"Note","Code":1276,"Message":"Field or reference 'test.posts.user_id' of SELECT #2 was resolved in SELECT #1"}]

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
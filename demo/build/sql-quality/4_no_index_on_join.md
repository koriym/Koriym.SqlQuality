# SQL Performance Analysis
- **SQL File:** `4_no_index_on_join.sql`
- **Cost:** 1462.81

## SQL
```sql
-- Problem: Missing index for GROUP BY
SELECT p.*, COUNT(*) as comment_count
FROM posts p
         LEFT JOIN comments c ON p.id = c.post_id
WHERE p.status = :status
GROUP BY p.id;

```

## Detected Issues
- 非効率的な結合が検出されました。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/IneffectiveJoin)
- グループ化のために一時テーブルが必要です。 [Learn more](https://koriym.github.io/Koriym.SqlQuality/issues/TemporaryTableGrouping)

## Explain Tree
```
JOIN
+- Index lookup
|  key             idx_posts_status_created
|  rows            2448
|  filtered        100.00
|  +- Table
|     table           p
+- Index lookup
   key             idx_comments_post_id
   rows            2
   filtered        100.00
   +- Table
      table           c
```
## Analysis Detail

### Schema
{"posts":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"title","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(255)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":"draft","EXTRA":""},{"COLUMN_NAME":"view_count","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":3},{"INDEX_NAME":"idx_posts_status_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":743},{"INDEX_NAME":"idx_posts_user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":994},{"INDEX_NAME":"idx_posts_user_status","COLUMN_NAME":"status","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":1450},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":4897}],"status":{"table_rows":4897,"data_length":540672,"index_length":491520,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-12 05:40:33"}},"comments":{"columns":[{"COLUMN_NAME":"id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"NO","COLUMN_KEY":"PRI","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"post_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"user_id","DATA_TYPE":"int","COLUMN_TYPE":"int","IS_NULLABLE":"YES","COLUMN_KEY":"MUL","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"content","DATA_TYPE":"text","COLUMN_TYPE":"text","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":null,"EXTRA":""},{"COLUMN_NAME":"status","DATA_TYPE":"varchar","COLUMN_TYPE":"varchar(20)","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"pending","EXTRA":""},{"COLUMN_NAME":"created_at","DATA_TYPE":"datetime","COLUMN_TYPE":"datetime","IS_NULLABLE":"YES","COLUMN_KEY":"","COLUMN_DEFAULT":"CURRENT_TIMESTAMP","EXTRA":"DEFAULT_GENERATED"}],"indexes":[{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4338},{"INDEX_NAME":"idx_comments_post_created","COLUMN_NAME":"created_at","NON_UNIQUE":1,"SEQ_IN_INDEX":2,"CARDINALITY":9980},{"INDEX_NAME":"idx_comments_post_id","COLUMN_NAME":"post_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":4338},{"INDEX_NAME":"PRIMARY","COLUMN_NAME":"id","NON_UNIQUE":0,"SEQ_IN_INDEX":1,"CARDINALITY":10284},{"INDEX_NAME":"user_id","COLUMN_NAME":"user_id","NON_UNIQUE":1,"SEQ_IN_INDEX":1,"CARDINALITY":1000}],"status":{"table_rows":10284,"data_length":1589248,"index_length":835584,"auto_increment":null,"create_time":"2025-02-11 11:45:34","update_time":"2025-02-12 05:40:33"}}}

### EXPLAIN JSON
{"select_id":1,"cost_info":{"query_cost":"1462.81"},"grouping_operation":{"using_temporary_table":true,"using_filesort":false,"nested_loop":[{"table":{"table_name":"p","access_type":"ref","possible_keys":["PRIMARY","idx_posts_user_id","idx_posts_status_created","idx_posts_user_status"],"key":"idx_posts_status_created","used_key_parts":["status"],"key_length":"83","ref":["const"],"rows_examined_per_scan":2448,"rows_produced_per_join":2448,"filtered":"100.00","cost_info":{"read_cost":"24.75","eval_cost":"244.80","prefix_cost":"269.55","data_read_per_join":"2M"},"used_columns":["id","user_id","title","content","status","view_count","created_at"]}},{"table":{"table_name":"c","access_type":"ref","possible_keys":["idx_comments_post_id","idx_comments_post_created"],"key":"idx_comments_post_id","used_key_parts":["post_id"],"key_length":"5","ref":["test.p.id"],"rows_examined_per_scan":2,"rows_produced_per_join":5803,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"612.92","eval_cost":"580.34","prefix_cost":"1462.81","data_read_per_join":"634K"},"used_columns":["id","post_id"]}}]}}

### EXPLAIN ANALYZE
-> Table scan on <temporary>  (actual time=10.4..10.7 rows=4500 loops=1)
    -> Aggregate using temporary table  (actual time=10.4..10.4 rows=4500 loops=1)
        -> Nested loop left join  (cost=1463 rows=5803) (actual time=0.00583..5.88 rows=9571 loops=1)
            -> Index lookup on p using idx_posts_status_created (status='published')  (cost=270 rows=2448) (actual time=0.00383..2 rows=4500 loops=1)
            -> Covering index lookup on c using idx_comments_post_id (post_id=p.id)  (cost=0.25 rows=2.37) (actual time=591e-6..753e-6 rows=1.99 loops=4500)

### SHOW WARNINGS
N/A

## Analysis Instructions
Create a SQL performance analysis report for this query. Begin with a table of key metrics showing current values and their impact. Then describe the detected issues, focusing on the root causes. Follow with specific improvement recommendations, including SQL examples and their expected impact. End with implementation priorities and any important considerations. Keep the analysis focused on actionable insights that will lead to significant performance gains.


以上の分析を日本語で記述してください。
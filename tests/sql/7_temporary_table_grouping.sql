-- 7_temporary_table_grouping.sql
-- Problem: Requires temporary table for grouping with ORDER BY
SELECT user_id, COUNT(*) as order_count
FROM orders
GROUP BY user_id
ORDER BY order_count DESC;

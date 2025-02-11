-- Problem: Correlated subquery in SELECT causing row-by-row execution
SELECT
    u.id,
    u.name,
    (SELECT MAX(created_at) FROM orders o WHERE o.user_id = u.id) as last_order_date
FROM users u;

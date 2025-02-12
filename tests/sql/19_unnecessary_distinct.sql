-- Problem: Unnecessary DISTINCT on already unique columns
SELECT DISTINCT id, created_at
FROM orders
WHERE user_id = 1
ORDER BY created_at;

-- Problem: Wide range scan causing excessive row examination
SELECT * FROM orders
WHERE total_amount BETWEEN 1 AND 1000
ORDER BY created_at;

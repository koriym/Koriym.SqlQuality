-- 9_inefficient_in_query.sql
-- Problem: Large IN clause without proper indexing
SELECT * FROM posts
WHERE status IN (:status1, :status2, :status3, :status4, :status5)
ORDER BY created_at;


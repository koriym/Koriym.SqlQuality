-- 8_suboptimal_or_condition.sql
-- Problem: OR condition preventing index usage
SELECT * FROM posts
WHERE user_id = :user_id
   OR status = 'published';


-- Problem: Using DATE function prevents index usage
SELECT * FROM posts
WHERE DATE(created_at) = :target_date;


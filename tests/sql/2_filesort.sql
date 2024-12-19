-- Problem: No index on created_at for sorting
SELECT * FROM posts
WHERE status = :status
ORDER BY created_at DESC
LIMIT :limit;

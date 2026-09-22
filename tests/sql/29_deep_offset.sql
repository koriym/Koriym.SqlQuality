-- Problem: Large OFFSET forces MySQL to scan and discard many rows before the page starts
SELECT *
FROM posts
ORDER BY created_at DESC
LIMIT :offset, :limit;

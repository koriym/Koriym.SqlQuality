-- Problem: Ineffective use of UNION requiring temporary table
SELECT id as item_id, 'post' as type, created_at
FROM posts
WHERE status = 'published'
UNION
SELECT id as item_id, 'comment' as type, created_at
FROM comments
WHERE post_id IN (
    SELECT id FROM posts WHERE status = 'published'
)
ORDER BY created_at;

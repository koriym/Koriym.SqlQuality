-- Problem: Missing index for GROUP BY
SELECT p.*, COUNT(*) as comment_count
FROM posts p
         LEFT JOIN comments c ON p.id = c.post_id
WHERE p.status = :status
GROUP BY p.id;

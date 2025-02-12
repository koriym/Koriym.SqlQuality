-- Problem: Multiple table update requiring table locks
UPDATE posts p
    JOIN comments c ON p.id = c.post_id
SET p.view_count = p.view_count + 1,
    c.content = CONCAT(c.content, ' [Updated]')
WHERE p.status = 'published'
  AND c.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY);

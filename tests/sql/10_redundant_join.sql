-- Problem: Unnecessary join when a subquery would be more efficient
SELECT u.*,
       (SELECT COUNT(*) FROM orders WHERE user_id = u.id) as order_count,
       (SELECT COUNT(*) FROM comments WHERE user_id = u.id) as comment_count
FROM users u
WHERE u.status = :status;

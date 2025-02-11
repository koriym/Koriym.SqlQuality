-- Problem: Excessive use of derived tables causing memory usage
SELECT * FROM
    (SELECT * FROM orders WHERE status = 'completed') o
        JOIN
    (SELECT * FROM users WHERE status = 'active') u
    ON o.user_id = u.id
        JOIN
    (SELECT * FROM posts WHERE status = 'published') p
    ON p.user_id = u.id;

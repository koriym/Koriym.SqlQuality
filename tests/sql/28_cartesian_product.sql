-- Problem: Comma-separated FROM without a join condition produces a Cartesian product
SELECT u.name, p.title
FROM users u, posts p
WHERE u.status = :status_u
  AND p.status = :status_p
LIMIT 10;

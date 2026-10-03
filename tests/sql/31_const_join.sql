-- Problem: A const-propagated join key is not a Cartesian product
SELECT u.id, u.name, p.title
FROM posts p
JOIN users u ON u.id = p.user_id
WHERE p.id = :post_id;

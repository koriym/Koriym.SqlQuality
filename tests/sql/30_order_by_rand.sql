-- Problem: ORDER BY RAND() forces a filesort over every matching row just to pick a few
SELECT *
FROM posts
WHERE status = :status
ORDER BY RAND()
LIMIT 5;

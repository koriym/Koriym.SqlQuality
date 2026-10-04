-- Problem: An aliased materialized derived table is not a full table scan to fix
SELECT /*+ NO_MERGE(d) */ d.user_id, d.cnt
FROM (
    SELECT user_id, COUNT(*) AS cnt
    FROM posts
    GROUP BY user_id
) AS d
WHERE d.cnt > :min_count;

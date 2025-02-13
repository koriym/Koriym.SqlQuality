-- 19_grouping_operation.sql
SELECT
  post_id
FROM
  comments
WHERE
  post_id IN (:post_ids)
GROUP BY
  post_id
HAVING
  count(*) = :target_count

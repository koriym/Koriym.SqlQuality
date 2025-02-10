-- 18_select_distinct.sql
SELECT DISTINCT
  user_id
FROM
  comments
WHERE
  user_id IN (:user_ids)
    AND id IN (:comment_ids)

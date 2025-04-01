-- 25_pass_the_with_clause.sql
WITH active_users AS (
	SELECT
    id
  FROM
    users
  WHERE
    status = 'active'
)
SELECT
  *
FROM
  comments
WHERE
  user_id
IN (
  SELECT
    id
  FROM
    active_users
)

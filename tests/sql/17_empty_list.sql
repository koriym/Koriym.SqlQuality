-- 17_empty_list.sql
SELECT * FROM posts WHERE posts.user_id IN (:empty_list);

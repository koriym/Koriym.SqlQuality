-- 16_listed_num_paramaters.sql
SELECT * FROM posts WHERE posts.user_id IN (:listed_params);

-- 16_listed_num_parameters.sql
SELECT * FROM posts WHERE posts.user_id IN (:listed_params);

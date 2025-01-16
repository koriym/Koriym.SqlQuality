-- 11_nested_loop.sql
SELECT `id`, `title`, `content`
FROM `posts`
WHERE EXISTS(SELECT 1
             FROM `users`
             WHERE `users`.`id` = `posts`.`user_id`
               AND `users`.`email` = :email);

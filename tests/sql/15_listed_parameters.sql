-- 15_listed_paramaters.sql
-- Problem: Listed parameters were not passed
SELECT * FROM users WHERE users.name IN (:listed_params);

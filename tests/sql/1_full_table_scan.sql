-- Problem: No index on view_count column
SELECT * FROM posts
WHERE view_count > :min_views;


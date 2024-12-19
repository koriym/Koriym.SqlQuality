-- Problem: Non-indexed LIKE with leading wildcard
SELECT * FROM posts
WHERE title LIKE :search_word
   OR content LIKE :search_word;

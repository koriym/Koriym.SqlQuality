-- Problem: Range scan with a low filter ratio, plus a leading-wildcard LIKE
SELECT * FROM comments WHERE post_id BETWEEN :min_post_id AND :max_post_id AND content LIKE :keyword;

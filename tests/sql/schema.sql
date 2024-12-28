-- schema.sql
DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
                       id INT PRIMARY KEY,
                       name VARCHAR(255),
                       email VARCHAR(255),
                       status VARCHAR(20),
                       created_at DATETIME,
                       updated_at DATETIME
);

CREATE TABLE posts (
                       id INT PRIMARY KEY,
                       user_id INT,
                       title VARCHAR(255),
                       content TEXT,
                       status VARCHAR(20),
                       view_count INT,
                       created_at DATETIME,
                       FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE comments (
                          id INT PRIMARY KEY,
                          post_id INT,
                          user_id INT,
                          content TEXT,
                          created_at DATETIME,
                          FOREIGN KEY (post_id) REFERENCES posts(id),
                          FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE orders (
                        id INT PRIMARY KEY,
                        user_id INT,
                        total_amount DECIMAL(10,2),
                        status VARCHAR(20),
                        created_at DATETIME,
                        reference_code VARCHAR(50),
                        FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Intentionally minimal indexes to demonstrate issues
CREATE INDEX idx_users_id ON users(id);
CREATE INDEX idx_posts_user_id ON posts(user_id);
CREATE INDEX idx_comments_post_id ON comments(post_id);
CREATE INDEX idx_orders_user_id ON orders(user_id);

-- Create procedure to generate test data
DELIMITER $$

CREATE PROCEDURE GenerateTestData()
BEGIN
    DECLARE i INT DEFAULT 1;
    
    -- Insert 1000 users
    WHILE i <= 1000 DO
        INSERT INTO users (id, name, email, status, created_at, updated_at)
        VALUES (
            i,
            CONCAT('User ', i),
            CONCAT('user', i, '@example.com'),
            CASE WHEN i % 10 = 0 THEN 'inactive' ELSE 'active' END,
            NOW() - INTERVAL FLOOR(RAND() * 365) DAY,
            NOW() - INTERVAL FLOOR(RAND() * 365) DAY
        );
        SET i = i + 1;
    END WHILE;
    
    -- Insert 5000 posts
    SET i = 1;
    WHILE i <= 5000 DO
        INSERT INTO posts (id, user_id, title, content, status, view_count, created_at)
        VALUES (
            i,
            FLOOR(1 + RAND() * 1000),
            CONCAT('Post Title ', i),
            CONCAT('This is the content of post ', i),
            CASE WHEN i % 20 = 0 THEN 'draft' ELSE 'published' END,
            FLOOR(RAND() * 10000),
            NOW() - INTERVAL FLOOR(RAND() * 365) DAY
        );
        SET i = i + 1;
    END WHILE;
    
    -- Insert 10000 comments
    SET i = 1;
    WHILE i <= 10000 DO
        INSERT INTO comments (id, post_id, user_id, content, created_at)
        VALUES (
            i,
            FLOOR(1 + RAND() * 5000),
            FLOOR(1 + RAND() * 1000),
            CONCAT('This is comment ', i),
            NOW() - INTERVAL FLOOR(RAND() * 365) DAY
        );
        SET i = i + 1;
    END WHILE;
    
    -- Insert 2000 orders
    SET i = 1;
    WHILE i <= 2000 DO
        INSERT INTO orders (id, user_id, total_amount, status, created_at, reference_code)
        VALUES (
            i,
            FLOOR(1 + RAND() * 1000),
            ROUND(RAND() * 1000, 2),
            CASE WHEN i % 50 = 0 THEN 'cancelled' ELSE 'completed' END,
            NOW() - INTERVAL FLOOR(RAND() * 365) DAY,
            CONCAT('REF', LPAD(i, 6, '0'))
        );
        SET i = i + 1;
    END WHILE;
END$$

DELIMITER ;

-- Generate test data
CALL GenerateTestData();

-- Clean up procedure
DROP PROCEDURE GenerateTestData;

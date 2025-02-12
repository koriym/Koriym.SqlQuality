DROP TABLE IF EXISTS orders;
DROP TABLE IF EXISTS comments;
DROP TABLE IF EXISTS posts;
DROP TABLE IF EXISTS users;

CREATE TABLE users (
                       id INT PRIMARY KEY,
                       name VARCHAR(255),
                       email VARCHAR(255),
                       status VARCHAR(20) DEFAULT 'active',
                       created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                       updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE posts (
                       id INT PRIMARY KEY,
                       user_id INT,
                       title VARCHAR(255),
                       content TEXT,
                       status VARCHAR(20) DEFAULT 'draft',
                       view_count INT,
                       created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                       FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE comments (
                          id INT PRIMARY KEY,
                          post_id INT,
                          user_id INT,
                          content TEXT,
                          status VARCHAR(20) DEFAULT 'pending',
                          created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                          FOREIGN KEY (post_id) REFERENCES posts(id),
                          FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE orders (
                        id INT PRIMARY KEY,
                        user_id INT,
                        total_amount DECIMAL(10,2),
                        status VARCHAR(20) DEFAULT 'pending',
                        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                        reference_code VARCHAR(50),
                        FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Base indexes
CREATE INDEX idx_users_id ON users(id);
CREATE INDEX idx_posts_user_id ON posts(user_id);
CREATE INDEX idx_comments_post_id ON comments(post_id);
CREATE INDEX idx_orders_user_id ON orders(user_id);

-- Additional indexes for new patterns
CREATE INDEX idx_users_status_created ON users(status, created_at);
CREATE INDEX idx_orders_status_created ON orders(status, created_at);
CREATE INDEX idx_orders_user_status ON orders(user_id, status);
CREATE INDEX idx_posts_status_created ON posts(status, created_at);
CREATE INDEX idx_posts_user_status ON posts(user_id, status);
CREATE INDEX idx_comments_post_created ON comments(post_id, created_at);

-- Test data generation procedure
DELIMITER $$

CREATE PROCEDURE GenerateTestData()
BEGIN
    DECLARE i INT DEFAULT 1;

    -- Insert users
    WHILE i <= 1000 DO
            INSERT INTO users (id, name, email, status, created_at, updated_at)
            VALUES (
                       i,
                       CONCAT('User ', i),
                       CONCAT('user', i, '@example.com'),
                       CASE
                           WHEN i % 10 = 0 THEN 'inactive'
                           WHEN i % 10 = 1 THEN 'pending'
                           ELSE 'active'
                           END,
                       NOW() - INTERVAL FLOOR(RAND() * 365) DAY,
                       NOW() - INTERVAL FLOOR(RAND() * 365) DAY
                   );
            SET i = i + 1;
        END WHILE;

    -- Insert posts
    SET i = 1;
    WHILE i <= 5000 DO
            INSERT INTO posts (id, user_id, title, content, status, view_count, created_at)
            VALUES (
                       i,
                       FLOOR(1 + RAND() * 1000),
                       CONCAT('Post Title ', i),
                       CONCAT('This is the content of post ', i),
                       CASE
                           WHEN i % 20 = 0 THEN 'draft'
                           WHEN i % 20 = 1 THEN 'pending'
                           ELSE 'published'
                           END,
                       FLOOR(RAND() * 10000),
                       NOW() - INTERVAL FLOOR(RAND() * 365) DAY
                   );
            SET i = i + 1;
        END WHILE;

    -- Insert comments
    SET i = 1;
    WHILE i <= 10000 DO
            INSERT INTO comments (id, post_id, user_id, content, status, created_at)
            VALUES (
                       i,
                       FLOOR(1 + RAND() * 5000),
                       FLOOR(1 + RAND() * 1000),
                       CONCAT('This is comment ', i),
                       CASE
                           WHEN i % 5 = 0 THEN 'pending'
                           ELSE 'approved'
                           END,
                       NOW() - INTERVAL FLOOR(RAND() * 365) DAY
                   );
            SET i = i + 1;
        END WHILE;

    -- Insert orders
    SET i = 1;
    WHILE i <= 2000 DO
            INSERT INTO orders (id, user_id, total_amount, status, created_at, reference_code)
            VALUES (
                       i,
                       FLOOR(1 + RAND() * 1000),
                       ROUND(RAND() * 1000, 2),
                       CASE
                           WHEN i % 50 = 0 THEN 'cancelled'
                           WHEN i % 50 = 1 THEN 'pending'
                           ELSE 'completed'
                           END,
                       NOW() - INTERVAL FLOOR(RAND() * 365) DAY,
                       CONCAT('REF', LPAD(i, 6, '0'))
                   );
            SET i = i + 1;
        END WHILE;
END$$

DELIMITER ;

-- Generate test data
CALL GenerateTestData();

-- Clean up
DROP PROCEDURE IF EXISTS GenerateTestData;

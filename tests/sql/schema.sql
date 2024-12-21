-- schema.sql
DROP TABLE users;
DROP TABLE posts;
DROP TABLE comments;
DROP TABLE orders;

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

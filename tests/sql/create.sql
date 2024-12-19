-- schema.sql
CREATE TABLE posts (
                       id INT PRIMARY KEY,
                       user_id INT,
                       title VARCHAR(255),
                       content TEXT,
                       status VARCHAR(20),
                       view_count INT,
                       created_at DATETIME,
                       updated_at DATETIME,
                       INDEX idx_user_id (user_id)
);

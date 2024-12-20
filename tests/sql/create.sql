-- schema.sql
CREATE TABLE users (
                       id INT PRIMARY KEY,
                       name VARCHAR(255),
                       email VARCHAR(255),
                       status VARCHAR(20),
                       created_at DATETIME,
                       updated_at DATETIME
);

CREATE TABLE orders (
                        id INT PRIMARY KEY,
                        user_id INT,
                        total_amount DECIMAL(10,2),
                        status VARCHAR(20),
                        created_at DATETIME,
                        FOREIGN KEY (user_id) REFERENCES users(id)
);

-- We have intentionally kept the index to a minimum.
CREATE INDEX idx_users_id ON users(id);
CREATE INDEX idx_orders_user_id ON orders(user_id);

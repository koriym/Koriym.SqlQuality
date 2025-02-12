-- Problem: Index on low cardinality column causing inefficient scans
SELECT * FROM users
WHERE status = 'active'
ORDER BY created_at;

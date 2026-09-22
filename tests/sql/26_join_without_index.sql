-- Problem: Join condition has no supporting index, forcing a hash join
SELECT o.id, u.name FROM orders o JOIN users u ON u.email = o.reference_code;

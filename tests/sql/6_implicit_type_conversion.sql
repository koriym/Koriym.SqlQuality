-- 6_implicit_type_conversion.sql
-- Problem: Implicit type conversion due to VARCHAR comparison with INT
SELECT * FROM orders
WHERE reference_code = 12345;

---
title: "一時テーブルを必要とするグループ化"
severity: "HIGH"
category: "Performance"
description: "一時テーブルを必要とする非効率なグループ化操作を検出します"
recommended: true
---

# TemporaryTableGrouping

一時テーブルを必要とする非効率なグループ化操作を検出します。

## 説明
一時テーブルが必要なグループ化は、以下のような状況で発生します：
- GROUP BY句に適したインデックスがない
- GROUP BYとORDER BYが異なるカラムを使用
- GROUP BY後の結果に対する集計や演算

## EXPLAINでの検出パターン
```sql
EXPLAIN FORMAT=JSON で以下のパターンを検出:
{
  "query_block": {
    "grouping_operation": {
      "using_temporary_table": true,  -- 一時テーブルの使用
      "using_filesort": true         -- ファイルソートも必要
    }
  }
}
```

## 問題のあるクエリの例
```sql
-- インデックスのないグループ化
SELECT category, COUNT(*) 
FROM products 
GROUP BY category;

-- GROUP BYとORDER BYが異なる
SELECT status, COUNT(*) 
FROM orders 
GROUP BY status 
ORDER BY COUNT(*) DESC;

-- 複雑な集計
SELECT customer_id, 
       AVG(amount) as avg_amount,
       COUNT(DISTINCT product_id) as unique_products
FROM orders 
GROUP BY customer_id;
```

## 推奨される対策

### 1. 適切なインデックスの作成
```sql
-- グループ化用のインデックス
ALTER TABLE products 
ADD INDEX idx_category (category);

-- 複合インデックス（追加の条件がある場合）
ALTER TABLE orders 
ADD INDEX idx_status_amount (status, amount);
```

### 2. サマリーテーブルの使用
```sql
-- サマリーテーブルの作成
CREATE TABLE order_summaries (
    customer_id INT,
    total_orders INT,
    total_amount DECIMAL(10,2),
    unique_products INT,
    PRIMARY KEY (customer_id)
);

-- 定期的な更新
INSERT INTO order_summaries
SELECT customer_id,
       COUNT(*) as total_orders,
       SUM(amount) as total_amount,
       COUNT(DISTINCT product_id) as unique_products
FROM orders
GROUP BY customer_id
ON DUPLICATE KEY UPDATE
    total_orders = VALUES(total_orders),
    total_amount = VALUES(total_amount),
    unique_products = VALUES(unique_products);
```

### 3. クエリの分割
```sql
-- 複雑なグループ化を分割
WITH order_counts AS (
    SELECT customer_id, COUNT(*) as order_count
    FROM orders 
    GROUP BY customer_id
)
SELECT oc.*, u.name
FROM order_counts oc
JOIN users u ON oc.customer_id = u.id;
```

## 例外ケース
以下の場合は警告を無視できる可能性があります：

1. レポート生成などの非リアルタイム処理
2. データ量が少ない場合
3. 実行頻度が低い管理用クエリ

## 参考
- [MySQL: GROUP BY Optimization](https://dev.mysql.com/doc/refman/8.0/en/group-by-optimization.html)
- [MySQL: Optimizing GROUP BY and DISTINCT](https://dev.mysql.com/doc/refman/8.0/en/distinct-optimization.html)
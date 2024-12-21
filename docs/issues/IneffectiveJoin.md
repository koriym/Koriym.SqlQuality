---
title: "非効率な結合操作"
severity: "HIGH"
category: "Performance"
description: "インデックスが適切に使用されていない結合操作を検出します"
recommended: true
---

# IneffectiveJoin

インデックスが適切に使用されていない、または非効率な結合操作を検出します。

## 説明
非効率な結合操作は、以下のような状況で発生します：
- 結合キーにインデックスがない
- 結合条件で関数や型変換が使用されている
- 大きなテーブル同士の結合で適切なインデックスがない

## EXPLAINでの検出パターン
```sql
EXPLAIN FORMAT=JSON で以下のパターンを検出:
{
  "query_block": {
    "nested_loop": [
      {
        "table": {
          "using_join_buffer": "Block Nested Loop",  -- 非効率な結合方式
          "rows": "large_number",                    -- 多数の行の処理
          "filtered": "low_value"                    -- 低い絞り込み効率
        }
      }
    ]
  }
}
```

## 問題のあるクエリの例
```sql
-- インデックスなしの結合
SELECT o.*, c.name 
FROM orders o 
JOIN customers c ON o.customer_id = c.id;

-- 関数を使用した結合
SELECT * FROM orders o 
JOIN products p ON LOWER(o.product_code) = LOWER(p.code);

-- 型変換が必要な結合
SELECT * FROM orders o 
JOIN legacy_orders lo ON o.order_id = lo.id;  -- 型が異なる
```

## 推奨される対策

### 1. 適切なインデックスの作成
```sql
-- 結合キーへのインデックス追加
ALTER TABLE orders ADD INDEX idx_customer (customer_id);

-- 複合インデックス（追加の条件がある場合）
ALTER TABLE orders 
ADD INDEX idx_customer_date (customer_id, created_at);
```

### 2. 結合条件の最適化
```sql
-- Before: 関数使用
SELECT * FROM orders o 
JOIN products p ON LOWER(o.product_code) = LOWER(p.code);

-- After: 正規化してから結合
ALTER TABLE orders MODIFY product_code VARCHAR(50) COLLATE utf8mb4_bin;
ALTER TABLE products MODIFY code VARCHAR(50) COLLATE utf8mb4_bin;
SELECT * FROM orders o 
JOIN products p ON o.product_code = p.code;
```

### 3. クエリの構造化
```sql
-- 小さいテーブルを先に指定
SELECT /*+ JOIN_ORDER(s, l) */ *
FROM small_table s
JOIN large_table l ON s.id = l.small_id;
```

## 例外ケース
以下の場合は警告を無視できる可能性があります：

1. 極めて小さいテーブル同士の結合
2. 一時テーブルとの結合
3. バッチ処理での非定期的な処理

## 参考
- [MySQL: JOIN Optimization](https://dev.mysql.com/doc/refman/8.0/en/join-optimization.html)
- [MySQL: Optimizing Database Joins](https://dev.mysql.com/doc/refman/8.0/en/optimize-join.html)
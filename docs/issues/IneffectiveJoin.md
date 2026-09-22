---
title: "非効率な結合操作"
severity: "HIGH"
category: "Performance"
description: "インデックスが適切に使用されていない結合操作を検出します"
recommended: true
---

# IneffectiveJoin

## 概要
- 重要度: HIGH
- カテゴリ: Performance
- 説明: インデックスが適切に使用されていない、または非効率な結合操作を検出します

## 検出パターン

### EXPLAIN出力での特徴
```
{
  "query_block": {
    "nested_loop": [
      {
        "table": {
          "table_name": "u",                       // 先頭は駆動表（この検出の対象外）
          "access_type": "ALL"
        }
      },
      {
        "table": {
          "table_name": "o",                       // 2番目以降の内部表が対象
          "access_type": "ALL",                    // 結合キーでインデックスを引けていない
          "using_join_buffer": "hash join",        // 結合バッファの使用
          "rows_examined_per_scan": "large_number", // 内部表で調べる行数
          "attached_condition": "(`u`.`email` = `o`.`reference_code`)" // 結合条件
        }
      }
    ]
  }
}
```

`nested_loop` の先頭は駆動表で、そのフルスキャンは `FullTableScan` が報告します。この検出は2番目以降の内部表だけを見ます。行数やコストの閾値はありません。

### 主な検出条件
1. `nested_loop` の2番目以降のメンバーであること
2. `access_type` が `ALL` または `index`、あるいは `using_join_buffer` があること
3. `attached_condition` に `この表.列 = 他の表.列` の形の結合条件があれば、この表の列へのインデックス作成（`index`）を提案。無い場合や、その列で始まるインデックスが既にある場合はレビュー（`review`）を提案

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 5-50倍増加
- メモリ使用: join_buffer_size（デフォルト256KB）×同時実行数
- ディスクI/O: 100-10000倍増加
- CPU使用率: 40-90%増加

### スケーラビリティ
- データ量に対して O(n×m) で劣化（n, mは結合テーブルの行数）
- 同時実行数に応じてメモリ競合が発生

## 例

### 問題のあるパターン

```sql
-- インデックスなしの結合
SELECT o.*, c.name 
FROM orders o 
JOIN customers c ON o.customer_id = c.id;

-- 関数を使用した結合
SELECT * 
FROM orders o 
JOIN products p 
  ON LOWER(o.product_code) = LOWER(p.code);

-- 型変換が必要な結合
SELECT * 
FROM orders o 
JOIN legacy_orders lo 
  ON o.order_id = CAST(lo.id AS UNSIGNED);

-- 複数テーブルの非効率な結合
SELECT 
    o.id,
    c.name,
    p.title,
    s.status
FROM orders o
JOIN customers c ON o.customer_id = c.id
JOIN products p ON o.product_id = p.id
JOIN shipments s ON o.id = s.order_id
WHERE c.country = 'US'
  AND p.category = 'Electronics';
```

### 推奨されるパターン

```sql
-- 適切なインデックスの作成
ALTER TABLE orders 
ADD INDEX idx_customer (customer_id);

ALTER TABLE orders 
ADD INDEX idx_product (product_id);

-- 結合条件の正規化
ALTER TABLE orders 
MODIFY product_code VARCHAR(50) COLLATE utf8mb4_bin;

ALTER TABLE products 
MODIFY code VARCHAR(50) COLLATE utf8mb4_bin;

-- 型の統一
ALTER TABLE legacy_orders 
MODIFY id INT UNSIGNED;

-- 複合インデックスと結合順序の最適化
ALTER TABLE customers 
ADD INDEX idx_country (country);

ALTER TABLE products 
ADD INDEX idx_category (category);

SELECT /*+ JOIN_ORDER(c, p, o, s) */
    o.id,
    c.name,
    p.title,
    s.status
FROM customers c
JOIN orders o ON c.id = o.customer_id
JOIN products p ON o.product_id = p.id
JOIN shipments s ON o.id = s.order_id
WHERE c.country = 'US'
  AND p.category = 'Electronics';
```

## 改善策の優先順位

1. インデックス作成
    - 難易度: 低
    - 効果: 高
    - リスク: ディスク使用量増加
    - 必要リソース: インデックス作成時間

2. データ型の統一
    - 難易度: 中
    - 効果: 中～高
    - リスク: アプリケーション互換性
    - 必要リソース: 開発工数、テスト工数

3. スキーマ設計の見直し
    - 難易度: 高
    - 効果: 高
    - リスク: 大規模な変更が必要
    - 必要リソース: 開発工数、移行時間

## 無視してよい場合

行数の閾値はないため、内部表の大きさは実行時間で判断します。

1. 小規模な内部表
    - 数百行程度で結合バッファに収まる
    - 実行時間が要件を満たしている

2. 一時的なレポート生成
    - バッチ処理での使用
    - 実行頻度が低い

3. 開発・テスト環境
    - データ量が少なく、本番と統計が異なる

## トラブルシューティング

### 調査手順

1. 結合バッファの確認
```sql
SHOW VARIABLES LIKE 'join_buffer_size';
SHOW STATUS LIKE 'Join%';
```

2. インデックス利用状況
```sql
EXPLAIN FORMAT=TREE SELECT ...;
SHOW INDEX FROM table_name;
```

3. テーブル統計の更新
```sql
ANALYZE TABLE table_name;
```

### メモリチューニング
```sql
-- 結合バッファのサイズ調整
SET GLOBAL join_buffer_size = 4194304; -- 4MB

-- ソートバッファの調整
SET GLOBAL sort_buffer_size = 2097152; -- 2MB
```

### 一般的な誤認識パターン

1. 小規模テーブルでの警告
    - 原因: 行数の閾値を持たず、結合方式だけで判定する
    - 対策: 内部表が小さく実行時間に問題がなければ無視する

2. インデックスが存在しても使用されない
    - 原因: オプティマイザの判断
    - 対策: JOIN_ORDER ヒントの使用

## 参考資料

- [MySQL: JOIN Optimization](https://dev.mysql.com/doc/refman/8.0/en/join-optimization.html)
- [MySQL: Optimizing Database Joins](https://dev.mysql.com/doc/refman/8.0/en/optimize-join.html)
- [MySQL: Nested Loop Join Algorithms](https://dev.mysql.com/doc/refman/8.0/en/nested-loop-joins.html)

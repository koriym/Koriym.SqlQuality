---
title: "関数によるインデックス無効化"
severity: "HIGH"
category: "Performance"
description: "関数使用によりインデックスが無効化される状況を検出します"
recommended: true
---

# FunctionInvalidatesIndex

## 概要
- 重要度: HIGH
- カテゴリ: Performance
- 説明: インデックス列に対する関数の使用によりインデックスが無効化される状況を検出します

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "table": {
      "attached_condition": "function_call",  // 関数使用
      "possible_keys": "index_name",          // インデックスは存在するが
      "key": null,                            // 使用されていない
      "rows": "large_number",                 // 多数の行をスキャン
      "filtered": "low_percentage",           // 低いフィルタ率
      "using_where": true,                    // WHERE句での評価
      "cost_info": {
        "read_cost": "high_value"            // 高い読み取りコスト
      }
    }
  }
}
```

### 主な検出条件
1. WHERE句でのカラムに対する関数使用
2. インデックス列に対する演算
3. 日付/時刻関数の使用（YEAR, MONTH, DATEなど）
4. 文字列関数の使用（LOWER, UPPER, CONCATなど）

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 3-20倍増加
- CPU使用率: 30-70%増加
- インデックス効率: 0%（完全に無効化）
- メモリ使用: テーブルサイズに応じて増加

### スケーラビリティ
- データ量に対して線形劣化（O(n)）
- 同時実行数に応じてCPU負荷が上昇
- メモリ使用量がテーブルサイズに比例

## 例

### 問題のあるパターン

```sql
-- 日付関数
SELECT * FROM orders 
WHERE YEAR(created_at) = 2024;

-- 文字列関数
SELECT * FROM users 
WHERE LOWER(email) = 'test@example.com';

-- 数値関数
SELECT * FROM products 
WHERE ABS(price) > 1000;

-- 文字列連結
SELECT * FROM customers 
WHERE CONCAT(first_name, ' ', last_name) = 'John Doe';

-- 複数の関数
SELECT * 
FROM transactions 
WHERE MONTH(transaction_date) = 3 
  AND UPPER(status) = 'COMPLETED'
  AND ROUND(amount, 2) > 100;

-- 計算を含む条件
SELECT * 
FROM order_items 
WHERE quantity * unit_price > 1000;
```

### 推奨されるパターン

```sql
-- 日付範囲を使用
SELECT * FROM orders 
WHERE created_at >= '2024-01-01' 
  AND created_at < '2025-01-01';

-- 計算済みカラムとインデックス
ALTER TABLE users 
ADD COLUMN email_lower VARCHAR(255) 
GENERATED ALWAYS AS (LOWER(email)) STORED,
ADD INDEX idx_email_lower (email_lower);

SELECT * FROM users 
WHERE email_lower = 'test@example.com';

-- インデックス可能な条件
SELECT * FROM products 
WHERE price > 1000 OR price < -1000;

-- 個別のカラムでの検索
SELECT * FROM customers 
WHERE first_name = 'John' 
  AND last_name = 'Doe';

-- 関数インデックス（MySQL 8.0+）
ALTER TABLE transactions 
ADD INDEX idx_month ((MONTH(transaction_date))),
ADD INDEX idx_status_upper ((UPPER(status)));

-- 計算済みカラム
ALTER TABLE order_items 
ADD COLUMN total_price DECIMAL(10,2) 
GENERATED ALWAYS AS (quantity * unit_price) STORED,
ADD INDEX idx_total_price (total_price);

SELECT * FROM order_items 
WHERE total_price > 1000;
```

## 改善策の優先順位

1. クエリの書き換え
    - 難易度: 低
    - 効果: 高
    - リスク: 低
    - 必要リソース: 開発時間

2. 計算済みカラムの追加
    - 難易度: 中
    - 効果: 高
    - リスク: ディスク使用量増加
    - 必要リソース: 開発時間、ディスク容量

3. 関数インデックスの使用
    - 難易度: 低
    - 効果: 中～高
    - リスク: インデックスメンテナンスコスト
    - 必要リソース: MySQL 8.0以上が必要

## 無視してよい場合

1. 小規模データ
    - テーブルサイズが1,000行未満
    - クエリの実行頻度が低い

2. データ分析用クエリ
    - バッチ処理
    - レポート生成

3. 一時的な集計
    - 管理機能での使用
    - 非リアルタイム処理

## トラブルシューティング

### 調査手順

1. 実行計画の確認
```sql
EXPLAIN FORMAT=JSON SELECT ...;
SHOW WARNINGS;
```

2. インデックス利用状況
```sql
SHOW INDEX FROM table_name;
```

3. クエリプロファイリング
```sql
SET profiling = 1;
SELECT ...;
SHOW PROFILE FOR QUERY 1;
```

### 関数の影響分析
```sql
-- カーディナリティの確認
SELECT COUNT(DISTINCT column_name) AS original_cardinality,
       COUNT(DISTINCT FUNCTION(column_name)) AS function_cardinality
FROM table_name;

-- 実行時間の比較
SET profiling = 1;
-- オリジナルクエリ
SELECT ...;
-- 最適化クエリ
SELECT ...;
SHOW PROFILES;
```

### 一般的な誤認識パターン

1. 必要な関数使用
    - 原因: ビジネスロジックの要件
    - 対策: 計算済みカラムの使用

2. 一時的な変換
    - 原因: データ移行やレポート生成
    - 対策: バッチ処理での許容

## 参考資料

- [MySQL 8.0: Functional Key Parts](https://dev.mysql.com/doc/refman/8.0/en/create-index.html#create-index-functional-key-parts)
- [MySQL: Generated Columns](https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html)
- [MySQL: Optimizer Use of Generated Column Indexes](https://dev.mysql.com/doc/refman/8.0/en/generated-column-index-optimizations.html)


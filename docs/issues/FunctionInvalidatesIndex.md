---
title: "関数によるインデックス無効化"
severity: "HIGH"
category: "Performance"
description: "関数使用によりインデックスが無効化される状況を検出します"
recommended: true
---

# FunctionInvalidatesIndex

インデックス列に対する関数の使用によりインデックスが無効化される状況を検出します。

## 説明
関数使用によるインデックス無効化は、以下のような状況で発生します：
- WHERE句でのカラムに対する関数使用
- 日付/時刻関数の使用（YEAR, MONTH, DATEなど）
- 文字列関数の使用（LOWER, UPPER, CONCATなど）

## EXPLAINでの検出パターン
```sql
EXPLAIN FORMAT=JSON で以下のパターンを検出:
{
  "query_block": {
    "table": {
      "attached_condition": "function_call",  -- 関数使用
      "possible_keys": "index_name",          -- インデックスは存在するが
      "key": null,                           -- 使用されていない
      "rows": "large_number",                -- 多数の行をスキャン
      "filtered": "low_percentage",          -- 低いフィルタ率
      "using_where": true,                   -- WHERE句での評価
      "cost_info": {
        "read_cost": "high_value"            -- 高い読み取りコスト
      }
    }
  }
}

## 問題のあるクエリの例
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
```

## 推奨される対策

### 1. 関数を使用しない条件式
```sql
-- Before
SELECT * FROM orders 
WHERE YEAR(created_at) = 2024;

-- After
SELECT * FROM orders 
WHERE created_at >= '2024-01-01' 
  AND created_at < '2025-01-01';
```

### 2. 計算済みカラムの使用
```sql
-- 計算済みカラムの追加
ALTER TABLE users 
ADD COLUMN email_lower VARCHAR(255) 
GENERATED ALWAYS AS (LOWER(email)) STORED;

-- インデックスの作成
ALTER TABLE users 
ADD INDEX idx_email_lower (email_lower);

-- クエリの変更
SELECT * FROM users 
WHERE email_lower = 'test@example.com';
```

### 3. インデックス定義の変更
```sql
-- MySQL 8.0以降で使用可能な関数インデックス
ALTER TABLE users 
ADD INDEX idx_email_lower ((LOWER(email)));
```

## 例外ケース
以下の場合は関数使用が許容される可能性があります：

1. 小規模テーブルでの検索
2. バッチ処理での非定期的な処理
3. 結果セットが極めて小さい場合

## 参考
- [MySQL 8.0: Functional Key Parts](https://dev.mysql.com/doc/refman/8.0/en/create-index.html#create-index-functional-key-parts)
- [MySQL: Generated Columns](https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html)
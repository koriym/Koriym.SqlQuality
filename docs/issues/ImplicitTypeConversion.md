---
title: "暗黙の型変換"
severity: "MEDIUM"
category: "Performance"
description: "暗黙的なデータ型変換によるパフォーマンス低下を検出します"
recommended: true
---

# ImplicitTypeConversion

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: 暗黙的なデータ型変換によるインデックス効率の低下を検出します

## 検出パターン

### EXPLAIN出力での特徴
```
{
  "query_block": {
    "table": {
      "table_name": "orders",
      "access_type": "ALL",                                                // 列に index があっても使えない
      "attached_condition": "(`test`.`orders`.`reference_code` = 12345)"  // 文字列型の列と数値リテラルの比較
    }
  }
}
```

### SHOW WARNINGS出力
```
Warning 1739: Cannot use ref access on index 'idx_orders_reference_code' due to type or collation conversion on field 'reference_code'
```
この警告があれば confidence 0.95、無ければ 0.8 で報告します。

### 主な検出条件
1. `attached_condition` または `index_condition` に `` `db`.`table`.`col` <op> <数値> `` の比較がある（`=`, `<>`, `!=`, `<`, `>`, `<=`, `>=`, `IN (数値, ...)`, `BETWEEN 数値 AND 数値`）
2. schema でその列の型が文字列型（char, varchar, text 系, enum, set）である
3. 関数で包まれた列（`cast(col as date)` 等）は対象外（FunctionInvalidatesIndex の領分）

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 2-5倍増加
- CPU使用率: 20-40%増加
- インデックス効率: 0-50%に低下
- メモリ使用: 変換バッファによる10-30%増加

### スケーラビリティ
- データ量に比例して劣化（O(n)）
- 同時実行数に応じてCPU負荷が上昇

## 例

### 問題のあるパターン

```sql
-- 数値と文字列の比較
SELECT * FROM orders 
WHERE order_id = '12345';  -- order_idはINT型

-- 異なる数値型の比較
SELECT * FROM products 
WHERE price = 19.99;  -- priceはDECIMAL(10,2)

-- 異なる文字セットの比較
SELECT * 
FROM users u 
JOIN legacy_users lu ON u.email = lu.email;  -- 文字セットが異なる

-- 暗黙の型変換を伴うJOIN
SELECT * 
FROM orders o 
JOIN product_codes pc ON o.product_id = pc.code;  -- INT vs VARCHAR

-- 日付型と文字列の比較
SELECT * 
FROM events 
WHERE event_date = '2024-01-01';  -- event_dateはDATETIME

-- 数値計算での型変換
SELECT product_id, quantity * '1.5' as adjusted_quantity 
FROM order_items;  -- quantityはINT
```

### 推奨されるパターン

```sql
-- データ型の統一
ALTER TABLE orders MODIFY order_id INT;

SELECT * FROM orders 
WHERE order_id = 12345;

-- 明示的な型変換
SELECT * FROM products 
WHERE price = CAST(19.99 AS DECIMAL(10,2));

-- 文字セットの統一
ALTER TABLE legacy_users 
MODIFY email VARCHAR(255) 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- 型を一致させたJOIN
ALTER TABLE product_codes 
MODIFY code INT;

SELECT * 
FROM orders o 
JOIN product_codes pc ON o.product_id = pc.code;

-- 日付型の適切な使用
SELECT * 
FROM events 
WHERE event_date = STR_TO_DATE('2024-01-01', '%Y-%m-%d');

-- 数値計算での明示的な型変換
SELECT product_id, 
       quantity * CAST('1.5' AS DECIMAL(10,2)) as adjusted_quantity 
FROM order_items;
```

## 改善策の優先順位

1. スキーマの型統一
    - 難易度: 中
    - 効果: 高
    - リスク: データ移行が必要
    - 必要リソース: 開発工数、移行時間

2. 明示的な型変換の使用
    - 難易度: 低
    - 効果: 中
    - リスク: 低
    - 必要リソース: コード修正時間

3. アプリケーションでの型変換
    - 難易度: 中
    - 効果: 高
    - リスク: アプリケーション変更必要
    - 必要リソース: 開発工数

## 無視してよい場合

1. 列に index が無く、他の条件で十分に絞られている
    - 型変換の有無で実行計画は変わらない
    - 行ごとの変換コストは残る

2. レガシーシステムとの互換性
    - 列の型変更が困難な場合
    - リテラルを文字列にする書き換えだけなら可能なことが多い

3. 一回限りの処理
    - データ移行スクリプト
    - 一時的なレポート生成

## トラブルシューティング

### 調査手順

1. 型変換の検出
```sql
EXPLAIN FORMAT=JSON SELECT ...;
SHOW WARNINGS;
```

2. カラム定義の確認
```sql
SHOW CREATE TABLE table_name;
SHOW FULL COLUMNS FROM table_name;
```

3. 文字セットの確認
```sql
SHOW VARIABLES LIKE 'character_set%';
SELECT COLLATION_NAME FROM information_schema.COLUMNS 
WHERE table_schema = DATABASE() 
  AND table_name = 'your_table' 
  AND column_name = 'your_column';
```

### パフォーマンス計測
```sql
-- クエリプロファイリング
SET profiling = 1;
SELECT ...;
SHOW PROFILES;
SHOW PROFILE FOR QUERY 1;

-- ステータスの確認
SHOW STATUS LIKE 'Created_tmp%';
SHOW STATUS LIKE 'Handler%';
```

### 一般的な誤認識パターン

1. 意図的な型変換
    - 原因: ビジネスロジックの要件
    - 対策: コメントでの説明追加

2. 一時的なキャスト
    - 原因: レポート生成などの一時的な要件
    - 対策: 実行頻度の考慮

## 参考資料

- [MySQL: Type Conversion in Expression Evaluation](https://dev.mysql.com/doc/refman/8.0/en/type-conversion.html)
- [MySQL: Cast Functions and Operators](https://dev.mysql.com/doc/refman/8.0/en/cast-functions.html)
- [MySQL: Character Set Configuration](https://dev.mysql.com/doc/refman/8.0/en/charset-configuration.html)

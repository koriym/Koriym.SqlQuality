---
title: "非効率な範囲スキャン"
severity: "MEDIUM"
category: "Performance"
description: "インデックスを効果的に使用できない範囲スキャン操作を検出します"
recommended: true
---

# IneffectiveRangeScan

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: インデックスを効果的に使用できない、または非効率な範囲スキャン操作を検出します

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "table": {
      "access_type": "ALL",              // フルテーブルスキャン
      "rows_examined_per_scan": "large",  // 多数の行の処理
      "filtered": "low_value",           // 低い絞り込み効率
      "attached_condition": "in (...)",   // IN句の使用
      "possible_keys": ["multiple_keys"], // 複数のインデックス候補
      "Using_filesort": "true"           // ファイルソートの使用
    }
  }
}
```

### 主な検出条件
1. IN句でのフルテーブルスキャン
2. 低選択性（20%未満）での範囲スキャン
3. OR条件での範囲スキャン
4. 大量の行（1000行以上）を処理する範囲スキャン
5. 複数のインデックス候補が存在する範囲スキャン
6. インデックスマージが必要な範囲スキャン

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 2-20倍増加
- メモリ使用: sort_buffer_size（デフォルト256KB）使用
- ディスクI/O: 10-1000倍増加
- CPU使用率: 20-60%増加

### スケーラビリティ
- データ量に対して線形または二次関数的に劣化
- 同時実行数増加によるメモリ競合
- 一時テーブル使用によるディスクI/O増加

## 例

### 問題のあるパターン

```sql
-- IN句での非効率なスキャン
SELECT * FROM users 
WHERE name IN ('User1', 'User2', ..., 'User1000');

-- OR条件での範囲スキャン
SELECT * FROM orders 
WHERE status = 'pending' 
   OR created_at BETWEEN '2023-01-01' AND '2023-12-31';

-- 複数条件での範囲スキャン
SELECT * FROM products 
WHERE (price BETWEEN 1000 AND 5000)
  AND (stock BETWEEN 10 AND 100);

-- 関数使用による範囲スキャン
SELECT * FROM users 
WHERE YEAR(created_at) = 2023 
  AND MONTH(created_at) BETWEEN 1 AND 6;
```

### 推奨されるパターン

```sql
-- IN句の代わりにJOINを使用
WITH user_list AS (
  SELECT 'User1' as name UNION ALL
  SELECT 'User2' UNION ALL ...
)
SELECT u.* 
FROM users u
JOIN user_list ul ON u.name = ul.name;

-- OR条件の分割とUNION
SELECT * FROM orders WHERE status = 'pending'
UNION ALL
SELECT * FROM orders 
WHERE created_at BETWEEN '2023-01-01' AND '2023-12-31';

-- 複合インデックスの使用
CREATE INDEX idx_price_stock ON products (price, stock);

SELECT * FROM products 
WHERE price BETWEEN 1000 AND 5000
  AND stock BETWEEN 10 AND 100;

-- 計算済みカラムとインデックス
ALTER TABLE users 
ADD COLUMN created_year INT GENERATED ALWAYS AS (YEAR(created_at));

CREATE INDEX idx_created_year ON users (created_year);

SELECT * FROM users 
WHERE created_year = 2023;
```

## 改善策の優先順位

1. インデックス最適化
    - 難易度: 低
    - 効果: 高
    - リスク: ディスク使用量増加
    - 必要リソース: インデックス作成時間

2. クエリ再設計
    - 難易度: 中
    - 効果: 高
    - リスク: アプリケーション変更必要
    - 必要リソース: 開発・テスト工数

3. スキーマ設計変更
    - 難易度: 高
    - 効果: 非常に高
    - リスク: 大規模な変更
    - 必要リソース: 開発・移行工数

## 無視してよい場合

1. 小規模データ
    - テーブルサイズが1,000行未満
    - 結果セットが小さい

2. バッチ処理
    - 夜間実行の集計処理
    - レポート生成処理

3. 開発環境
    - パフォーマンスが重要でない
    - データ量が少ない

## トラブルシューティング

### 調査手順

1. インデックス使用状況の確認
```sql
EXPLAIN FORMAT=TREE SELECT ...;
SHOW INDEX FROM table_name;
```

2. テーブル統計の確認
```sql
SHOW TABLE STATUS LIKE 'table_name';
ANALYZE TABLE table_name;
```

3. 実行計画の詳細分析
```sql
EXPLAIN ANALYZE SELECT ...;
```

### インデックス設計

1. カーディナリティの確認
```sql
SELECT COUNT(DISTINCT column_name) / COUNT(*) as selectivity
FROM table_name;
```

2. インデックス使用率
```sql
SELECT index_name, count_read, count_fetch
FROM performance_schema.table_io_waits_summary_by_index_usage
WHERE object_schema = 'database_name'
  AND object_name = 'table_name';
```

### 一般的な誤認識パターン

1. 小規模IN句での警告
    - 原因: 値の数が少ない
    - 対策: 閾値の調整

2. 計画的なフルスキャン
    - 原因: オプティマイザの選択
    - 対策: ヒントの使用

## 参考資料

- [MySQL: Range Optimization](https://dev.mysql.com/doc/refman/8.0/en/range-optimization.html)
- [MySQL: Index Range Scan](https://dev.mysql.com/doc/refman/8.0/en/explain-output.html#jointype_range)
- [MySQL: Multiple-Column Indexes](https://dev.mysql.com/doc/refman/8.0/en/multiple-column-indexes.html)

---
title: "非効率なUNION操作"
severity: "MEDIUM"
category: "Performance" 
description: "非効率なUNION操作による一時テーブルの作成を検出します"
recommended: true
---

# IneffectiveUnion

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: 一時テーブルを必要とする非効率なUNION操作を検出します

## 検出パターン

### EXPLAIN出力での特徴
```
{
  "query_block": {
    "union_result": {
      "using_temporary_table": true,     // 一時テーブルの使用
      "table": {
        "union": [
          {
            "cost_info": {
              "sort_cost": "high_value"  // 高いソートコスト
            }
          }
        ]
      }
    }
  }
}
```

### 主な検出条件
1. UNION操作での一時テーブルの作成
2. UNION ALLの代わりにUNIONを使用
3. 重複排除が不要な場合でのUNIONの使用
4. 大量のデータに対するUNION操作

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 2-10倍増加
- メモリ使用: tmp_table_size（デフォルト16MB）までのメモリ使用
- ディスクI/O: 一時テーブルがメモリを超えた場合、100-1000倍増加
- CPU使用率: 20-60%増加（重複排除処理による）

### スケーラビリティ
- データ量に対して O(n log n) で劣化（nは結果セットの行数）
- 一時テーブルのサイズがtmp_table_sizeを超えた場合、ディスクI/Oが発生

## 例

### 問題のあるパターン

```sql
-- 不必要な重複排除
SELECT id, name, 'active' as status
FROM current_users
UNION
SELECT id, name, 'inactive' as status
FROM archived_users;

-- 大量データのUNION
SELECT *
FROM sales_2022
UNION
SELECT *
FROM sales_2023
UNION
SELECT *
FROM sales_2024;

-- 複雑な条件でのUNION
SELECT 
    u.id,
    u.name,
    'premium' as type
FROM users u
JOIN subscriptions s ON u.id = s.user_id
WHERE s.plan = 'premium'
UNION
SELECT 
    u.id,
    u.name,
    'basic' as type
FROM users u
WHERE u.id NOT IN (
    SELECT user_id 
    FROM subscriptions
);
```

### 推奨されるパターン

```sql
-- UNION ALLの使用（重複がない場合）
SELECT id, name, 'active' as status
FROM current_users
UNION ALL
SELECT id, name, 'inactive' as status
FROM archived_users;

-- パーティショニングの活用
CREATE TABLE sales (
    id INT,
    amount DECIMAL(10,2),
    sale_date DATE
) PARTITION BY RANGE (YEAR(sale_date)) (
    PARTITION p2022 VALUES LESS THAN (2023),
    PARTITION p2023 VALUES LESS THAN (2024),
    PARTITION p2024 VALUES LESS THAN (2025)
);

-- 結合を使用した代替実装
SELECT 
    u.id,
    u.name,
    CASE 
        WHEN s.plan IS NOT NULL THEN 'premium'
        ELSE 'basic'
    END as type
FROM users u
LEFT JOIN subscriptions s ON u.id = s.user_id;
```

## 改善策の優先順位

1. UNION ALL への変更
    - 難易度: 低
    - 効果: 高
    - リスク: 低（重複確認が必要）
    - 必要リソース: コード修正のみ

2. クエリの書き換え
    - 難易度: 中
    - 効果: 高
    - リスク: 中（ロジック変更）
    - 必要リソース: 開発工数、テスト工数

3. テーブル設計の見直し
    - 難易度: 高
    - 効果: 高
    - リスク: 高（大規模変更）
    - 必要リソース: 開発工数、移行時間

## 無視してよい場合

1. 小規模データセット
    - 各UNIONクエリが1,000行未満
    - 結果セットが小さい

2. 重複排除が必須
    - ビジネスロジック上、重複を排除する必要がある
    - データの整合性確認

3. 開発・テスト環境
    - パフォーマンスが重要でない
    - 一時的な検証用クエリ

## トラブルシューティング

### 調査手順

1. 一時テーブルの使用状況確認
```sql
SHOW STATUS LIKE 'Created_tmp%';
SHOW VARIABLES LIKE 'tmp_table_size';
```

2. UNION操作の実行計画確認
```sql
EXPLAIN FORMAT=TREE SELECT ...;
EXPLAIN ANALYZE SELECT ...;
```

3. メモリ使用状況の確認
```sql
SHOW STATUS LIKE 'Memory_used';
```

### メモリチューニング
```sql
-- 一時テーブルのサイズ調整
SET GLOBAL tmp_table_size = 33554432; -- 32MB

-- メモリテーブルのサイズ調整
SET GLOBAL max_heap_table_size = 33554432; -- 32MB
```

### 一般的な誤認識パターン

1. 必要な重複排除での警告
    - 原因: ビジネスロジック上の要件
    - 対策: 警告の抑制

2. パフォーマンスが問題ない場合
    - 原因: データ量が少ない
    - 対策: 監視閾値の調整

## 参考資料

- [MySQL: UNION Syntax](https://dev.mysql.com/doc/refman/8.0/en/union.html)
- [MySQL: Internal Temporary Tables](https://dev.mysql.com/doc/refman/8.0/en/internal-temporary-tables.html)
- [MySQL: Optimizing UNION Statements](https://dev.mysql.com/doc/refman/8.0/en/union-optimization.html)

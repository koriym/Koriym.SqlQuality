---
title: "依存サブクエリ"
severity: "MEDIUM"
category: "Performance"
description: "外側の行ごとに実行される相関サブクエリを検出します"
recommended: true
---

# DependentSubquery

## 概要
- 重要度: MEDIUM（外側の行数が 1,000 以上なら HIGH）
- カテゴリ: Performance
- 説明: EXPLAIN が `dependent: true` を付けたサブクエリを検出します。外側のクエリが返す行ごとに 1 回ずつ実行されます

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "table": {"table_name": "u", "access_type": "ALL", "rows_produced_per_join": 1000},
    "select_list_subqueries": [
      {
        "dependent": true,
        "cacheable": false,
        "query_block": {"select_id": 2, "table": {"table_name": "o", "ref": ["test.u.id"]}}
      }
    ]
  }
}
```

### 主な検出条件
1. EXPLAIN JSON のいずれかのノードに `dependent: true` がある（SELECT 句、WHERE 句、HAVING 句、ORDER BY 句のどれでも）
2. `outer_rows` は同じ query_block にあるテーブルの `rows_produced_per_join` の最大値
3. `SHOW WARNINGS` の Note 1276（外側の列への参照）があれば evidence の `warning` に入る

`EXISTS` は MySQL 8.0 で semijoin に変換されることが多く、その場合 `dependent` は付かないので検出されません。

## パフォーマンスへの影響
- 実行回数: 外側の行数 × 1
- 内側にインデックスがあっても、外側が 1,000 行なら 1,000 回のインデックス検索
- 内側がテーブルスキャンなら、外側の行数 × 内側の行数の読み取り

## 例

### 問題のあるパターン
```sql
SELECT u.id, u.name,
       (SELECT MAX(created_at) FROM orders o WHERE o.user_id = u.id) AS last_order_date
FROM users u;
```

### 推奨されるパターン
```sql
SELECT u.id, u.name, o.last_order_date
FROM users u
LEFT JOIN (
    SELECT user_id, MAX(created_at) AS last_order_date
    FROM orders
    GROUP BY user_id
) o ON o.user_id = u.id;
```

## 改善策の優先順位
1. JOIN + GROUP BY か派生テーブルへの書き換え（難易度: 中、効果: 高）
2. サブクエリの結合列へのインデックス作成（難易度: 低、効果: 中。実行回数は減らない）
3. 外側の行数を WHERE で絞る（難易度: 低、効果: 絞り込み次第）

## 無視してよい場合
1. 外側が主キー検索などで数行しか返さない
2. 実行頻度の低い管理用クエリ

## トラブルシューティング
```sql
EXPLAIN FORMAT=JSON SELECT ...;  -- "dependent": true を探す
EXPLAIN ANALYZE SELECT ...;      -- サブクエリ行の loops= が外側の行数
SHOW WARNINGS;                   -- Note 1276 が外側への参照を示す
```

## 参考資料
- [MySQL: Correlated Subqueries](https://dev.mysql.com/doc/refman/8.0/en/correlated-subqueries.html)
- [MySQL: Optimizing Subqueries, Derived Tables, View References, and Common Table Expressions](https://dev.mysql.com/doc/refman/8.0/en/subquery-optimization.html)

---
title: "カーテシアン積"
severity: "MEDIUM"
category: "Performance"
description: "先行テーブルと結びつく結合キーのない結合を検出します"
recommended: true
---

# CartesianProduct

## 概要
- 重要度: MEDIUM（生成される行数が 100,000 以上なら HIGH）
- カテゴリ: Performance
- 説明: `nested_loop` の 2 番目以降のテーブルが、それまでに結合済みのテーブルと結びつく結合キーを持たずに実行される状態を検出します。行数は先行テーブルの行数 × このテーブルの行数で増えます

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "nested_loop": [
      {"table": {"table_name": "u", "access_type": "ref", "ref": ["const"]}},
      {"table": {"table_name": "p", "access_type": "ref", "ref": ["const"], "rows_produced_per_join": 1982400}}
    ]
  }
}
```

### 主な検出条件
1. `nested_loop` の 2 番目以降のメンバーである（先頭のテーブルは対象外）
2. `ref` が無いか、要素が全て `"const"`（先行テーブルの列を参照していない）
3. `attached_condition` が無いか、先行テーブルの別名を `` `db`.`alias`.`col` `` の形で参照していない
4. `access_type` が `eq_ref` ではない（主キー・一意キーでの結合ではない）

`FROM users u, posts p` のようにカンマ区切りで並べただけで `ON` や `WHERE` に結合条件が無いと、MySQL は各テーブルを自身の条件だけで絞り込んでから掛け合わせます。`using_join_buffer` の有無は判定に使いません（ブロックネステッドループでも直積は直積のため）

## パフォーマンスへの影響
- 生成行数: 先行テーブルの一致行数 × このテーブルの一致行数
- 例: 800 行 × 2,478 行 = 1,982,400 行を後続の WHERE / LIMIT で絞り込む
- インデックスが効いて各テーブル単体の絞り込みが速くても、掛け合わせ自体は減らない

## 例

### 問題のあるパターン
```sql
SELECT u.name, p.title
FROM users u, posts p
WHERE u.status = 'active'
  AND p.status = 'published'
LIMIT 10;
```

### 推奨されるパターン
```sql
SELECT u.name, p.title
FROM users u
JOIN posts p ON p.user_id = u.id
WHERE u.status = 'active'
  AND p.status = 'published'
LIMIT 10;
```

## 改善策の優先順位
1. `ON` 句（または `WHERE` の結合条件）を追加する（難易度: 低、効果: 高）
2. 意図した `CROSS JOIN` なら明記し、`LIMIT` や集計で生成行数を抑える（難易度: 低、効果: 中）
3. 結合列にインデックスを作成する（難易度: 低、効果: 結合条件を追加した後に有効）

## 無視してよい場合
1. 一方のテーブルが 1 行しか返さない意図的な `CROSS JOIN`（設定値テーブルとの掛け合わせなど）
2. 両テーブルとも極小行数で、生成行数が実用上問題にならない

## トラブルシューティング
```sql
EXPLAIN FORMAT=JSON SELECT ...;  -- nested_loop の各メンバーの ref を確認
SHOW WARNINGS;                   -- 書き換え後の SQL に結合条件があるか確認
```

## 参考資料
- [MySQL: JOIN Clause](https://dev.mysql.com/doc/refman/8.0/en/join.html)
- [MySQL: Nested-Loop Join Algorithms](https://dev.mysql.com/doc/refman/8.0/en/nested-loop-joins.html)

---
title: "ORDER BY RAND()"
severity: "MEDIUM"
category: "Performance"
description: "ORDER BY RAND() による全件フィルソートを検出します"
recommended: true
---

# OrderByRand

## 概要
- 重要度: MEDIUM（`ordering_operation` 配下のテーブルの `rows_examined_per_scan` が 10,000 以上なら HIGH）
- カテゴリ: Performance
- 説明: `ORDER BY RAND()` を検出します。行ごとに乱数を振って並べ替えるため、`LIMIT` で件数を絞っていても一致した行全体にフィルソートが必要になります

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "ordering_operation": {
      "using_filesort": true,
      "using_temporary_table": true,
      "table": {"table_name": "posts", "access_type": "ref", "rows_examined_per_scan": 2478}
    }
  }
}
```

### 主な検出条件
1. SQL文（パラメータ展開後）に `ORDER BY RAND(` がある（大文字小文字・空白量は問わない）

`RAND()` の結果はインデックスを使えないため、MySQL は一致した行を一時テーブルに集め、フィルソートしてから `LIMIT` を適用します。`WHERE` で絞り込んだ後の行数がそのままソート対象になります。

## パフォーマンスへの影響
- フィルソート対象行数: `WHERE` 条件に一致した行数全体（`LIMIT` の件数とは無関係）
- `using_temporary_table` と `using_filesort` が同時に立ち、CPU とディスク/メモリの両方を消費する
- テーブルが大きいほど、少数の行を返すだけの処理が全体スキャンに近いコストになる

## 例

### 問題のあるパターン
```sql
SELECT *
FROM posts
WHERE status = 'published'
ORDER BY RAND()
LIMIT 5;
```

### 推奨されるパターン
```sql
SELECT p.*
FROM posts p
JOIN (
  SELECT id FROM posts
  WHERE id >= (SELECT FLOOR(RAND() * MAX(id)) FROM posts)
    AND status = 'published'
  ORDER BY id
  LIMIT 5
) AS r ON r.id = p.id;
```

## 改善策の優先順位
1. 主キー範囲から乱数でオフセットする方式（`WHERE id >= (SELECT FLOOR(RAND() * MAX(id)) …) ORDER BY id LIMIT n`）に書き換える（難易度: 中、効果: 高）
2. 候補が少数なら、アプリ側で候補IDを乱数抽出してから `WHERE id IN (...)` で取得する（難易度: 低、効果: 高）
3. 頻繁に使う乱数抽出用に、事前計算済みのランダム列や専用テーブルを用意する（難易度: 高、効果: 高）

## 無視してよい場合
1. 対象テーブル（`WHERE` 適用後）の行数が少なく、フィルソートのコストが無視できる
2. バッチ処理など、実行頻度が低く応答時間が問題にならない用途

## トラブルシューティング
```sql
EXPLAIN FORMAT=JSON SELECT ...;  -- ordering_operation の using_temporary_table / using_filesort を確認
SHOW WARNINGS;                   -- 書き換え後のSQLでRAND()の位置を確認
```

## 参考資料
- [MySQL: Mathematical Functions (RAND())](https://dev.mysql.com/doc/refman/8.0/en/mathematical-functions.html#function_rand)
- [MySQL: LIMIT Query Optimization](https://dev.mysql.com/doc/refman/8.0/en/limit-optimization.html)

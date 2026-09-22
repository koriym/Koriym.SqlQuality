---
title: "大きなOFFSET"
severity: "MEDIUM"
category: "Performance"
description: "破棄される行数の多いLIMIT ... OFFSET ページネーションを検出します"
recommended: true
---

# DeepOffset

## 概要
- 重要度: MEDIUM（offset が 100,000 以上なら HIGH）
- カテゴリ: Performance
- 説明: `LIMIT offset, count` または `LIMIT count OFFSET offset` の `offset` が 1,000 以上のクエリを検出します。MySQL は結果を返す前に `offset` 件分の行を読み飛ばすため、ページが深くなるほど読み捨てる行数が線形に増えます

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "ordering_operation": {
      "using_filesort": true,
      "table": {"table_name": "posts", "rows_examined_per_scan": 4956}
    }
  }
}
```

### 主な検出条件
1. SQL文（パラメータ展開後）に `LIMIT offset, count` または `LIMIT count OFFSET offset` があり `offset >= 1000`
2. サブクエリ内の `LIMIT` も対象。SQL文中で最初に見つかったものを使う
3. `ordering_operation` があれば、その `using_filesort` と対象テーブルの `rows_examined_per_scan` を併せて記録する（ソートとオフセットが重なると特に遅い）

`rows_examined_per_scan` はテーブル全体の見積もりであり、`offset` による読み飛ばし自体は EXPLAIN の行数に現れません。オフセットが深いほど、実行時間は `offset + count` 件のスキャンに比例して伸びます。

## パフォーマンスへの影響
- 読み飛ばす行数: `offset` 件（結果には含まれない）
- インデックスで `ORDER BY` を満たせても、offset 件を数えるまでは行を返せない
- 例: `offset = 10000` なら、10 件を返すためだけに 10,010 件分の走査が必要になる

## 例

### 問題のあるパターン
```sql
SELECT *
FROM posts
ORDER BY created_at DESC
LIMIT 10000, 10;
```

### 推奨されるパターン
```sql
SELECT *
FROM posts
WHERE created_at < :last_created_at
   OR (created_at = :last_created_at AND id < :last_id)
ORDER BY created_at DESC, id DESC
LIMIT 10;
```

## 改善策の優先順位
1. キーセットページネーション（`WHERE (sort_col, id) < (:last_sort, :last_id)`）に書き換える（難易度: 中、効果: 高）
2. ソート列に一意なタイブレーク列（`id` など）を加えた複合インデックスを作成する（難易度: 低、効果: キーセットページネーションと組み合わせて有効）
3. 深いページ番号へのジャンプ自体をUIから減らす（無限スクロール・前後移動のみに限定する）（難易度: 中、効果: 高）

## 無視してよい場合
1. 対象テーブルの行数が少なく（数千件程度）、`offset` が増えても体感できる遅延が出ない
2. バッチ処理など、実行時間がユーザー応答に影響しない用途で一度だけ実行する

## トラブルシューティング
```sql
EXPLAIN FORMAT=JSON SELECT ...;  -- ordering_operation の有無と対象テーブルを確認
SHOW WARNINGS;                   -- 書き換え後のSQLでLIMIT/OFFSETの値を確認
```

## 参考資料
- [MySQL: LIMIT Query Optimization](https://dev.mysql.com/doc/refman/8.0/en/limit-optimization.html)
- [MySQL: SELECT Statement](https://dev.mysql.com/doc/refman/8.0/en/select.html)

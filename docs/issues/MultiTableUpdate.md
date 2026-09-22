---
title: "複数テーブルの更新"
severity: "MEDIUM"
category: "Performance"
description: "単一のUPDATE文で複数テーブルを同時に更新する状態を検出します"
recommended: true
---

# MultiTableUpdate

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: 1 つの `UPDATE` 文で複数のテーブルを同時に更新するマルチテーブル UPDATE を検出します。更新対象の全テーブルに行ロックがかかるため、単一テーブルの UPDATE より広い範囲をロックします

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "nested_loop": [
      {"table": {"table_name": "comments", "update": true, "access_type": "ALL"}},
      {"table": {"table_name": "posts", "update": true, "access_type": "eq_ref"}}
    ]
  }
}
```
更新対象の各テーブルに `"update": true` が付く。バージョンによっては `query_block.update_operation` に文字列 `"multi_table"` が入ることもある

### 主な検出条件
1. `table` 配下に `"update": true` を持つテーブルが 2 つ以上ある、または
2. `update_operation` が文字列 `"multi_table"` である

## パフォーマンスへの影響
- 更新対象の全テーブルで行ロックが発生し、他のトランザクションと衝突しやすくなる
- 結合条件を誤ると、意図しないテーブルの行まで更新される
- 関わるテーブルが多いほど、ロールバック時に巻き戻す undo ログも増える

## 例

### 問題のあるパターン
```sql
UPDATE posts p
JOIN comments c ON c.post_id = p.id
SET p.comment_count = p.comment_count + 1,
    c.status = 'counted'
WHERE c.id = 123;
```

### 推奨されるパターン
```sql
UPDATE posts SET comment_count = comment_count + 1
WHERE id = (SELECT post_id FROM comments WHERE id = 123);

UPDATE comments SET status = 'counted' WHERE id = 123;
```

## 改善策の優先順位
1. 更新を単一テーブルの文に分割し、必要な範囲だけトランザクションでまとめる（難易度: 中、効果: 高）
2. 分割できない場合は、結合条件と `WHERE` 句を絞り込みロック対象行を最小限にする（難易度: 低、効果: 中）
3. 複数箇所から同じ組み合わせのテーブルを更新するなら、更新順序を揃えてデッドロックを避ける（難易度: 低、効果: デッドロック対策として有効）

## 無視してよい場合
1. 対象行数が少なく、ロック範囲・実行時間が問題にならない
2. 他のトランザクションと同時実行されないバッチ処理

## トラブルシューティング
```sql
EXPLAIN FORMAT=JSON UPDATE ...;  -- 各テーブルの update / update_operation を確認
SHOW ENGINE INNODB STATUS;       -- ロック待ち・デッドロックの発生を確認
```

## 参考資料
- [MySQL: UPDATE Statement](https://dev.mysql.com/doc/refman/8.0/en/update.html)
- [MySQL: InnoDB Locking](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking.html)

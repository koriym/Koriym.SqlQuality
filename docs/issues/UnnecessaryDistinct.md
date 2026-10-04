---
title: "Unnecessary DISTINCT"
severity: "LOW"
category: "Performance"
description: "既にユニークな列に対する不要なDISTINCTを検出します"
recommended: true
---

# UnnecessaryDistinct

## 概要
- 重要度: LOW
- カテゴリ: Performance
- 説明: 主キーや一意制約のある列を含む結果セットに対して、不要なDISTINCT演算を行っている状態を検出します

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "ordering_operation": {
      "duplicates_removal": {        // DISTINCT の重複除去
        "using_filesort": false,
        "table": {
          "table_name": "orders",
          "access_type": "ref"
        }
      }
    }
  }
}
```

判定は EXPLAIN と SQL 文と schema で行います。`SELECT DISTINCT` で始まり FROM が単一テーブル（JOIN やカンマ区切りなし）の文で、SELECT リストに主キー（`information_schema` の `COLUMN_KEY = 'PRI'`）の全列が含まれていれば、各行は主キーで既に一意なので DISTINCT は不要です。`*` は全列を含むとみなします。SELECT リストに関数呼び出しがある場合は判定しません。

### 主な検出条件
1. SQL が `SELECT DISTINCT` で始まり、FROM が単一テーブルであること
2. EXPLAIN に `duplicates_removal` があること
3. SELECT リストに主キーの全列（複合主キーならその全て）が含まれること
4. 提案は書き換え（`rewrite`）。`DISTINCT` を除いた文を `sql` に含む

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 5-15%増加（小規模データ）
- メモリ使用: 重複除去のための一時バッファ
- CPU使用率: 比較演算のオーバーヘッド

### スケーラビリティ
- 大量データでは重複チェックコストが増加
- ソート済みデータでも追加の比較処理が発生
- メモリ使用量が結果セットサイズに比例

## 例

### 問題のあるパターン

```sql
-- 主キーを含むDISTINCT（不要）
SELECT DISTINCT id, created_at
FROM orders
WHERE user_id = 1;

-- ユニーク制約カラムでのDISTINCT
SELECT DISTINCT email, name
FROM users
WHERE status = 'active';

-- JOINでも主キーがあればユニーク
SELECT DISTINCT o.id, o.total_amount
FROM orders o
JOIN users u ON o.user_id = u.id
WHERE u.status = 'active';
```

### 推奨されるパターン

```sql
-- DISTINCTを削除
SELECT id, created_at
FROM orders
WHERE user_id = 1;

-- 必要な場合は明示的にコメント
SELECT email, name  -- emailはUNIQUE制約
FROM users
WHERE status = 'active';

-- 主キーがあればDISTINCT不要
SELECT o.id, o.total_amount
FROM orders o
JOIN users u ON o.user_id = u.id
WHERE u.status = 'active';
```

## 改善策の優先順位

1. DISTINCT削除
    - 難易度: 低
    - 効果: 小～中
    - リスク: なし（ロジックが正しい場合）
    - 必要リソース: コード修正のみ

2. ユニーク性の検証
    - 難易度: 中
    - 効果: データ整合性向上
    - リスク: 既存データの問題発覚
    - 必要リソース: テスト工数

## 無視してよい場合

1. ドキュメント目的
    - 意図を明示するため
    - チーム規約

2. 将来の拡張性
    - JOIN の追加やスキーマ変更の予定があり、主キーによる一意性が保てなくなる

## トラブルシューティング

### 調査手順

1. 制約の確認
```sql
SHOW CREATE TABLE table_name;
SHOW INDEX FROM table_name;
```

2. 実際のデータ確認
```sql
-- 重複があるか確認
SELECT column1, column2, COUNT(*)
FROM table_name
GROUP BY column1, column2
HAVING COUNT(*) > 1;
```

### 一般的な誤認識パターン

1. サブクエリの結果
    - 原因: サブクエリが重複を返す可能性
    - 対策: サブクエリ側で重複除去

2. 集約関数との併用
    - 原因: GROUP BYとDISTINCTの混同
    - 対策: GROUP BYの使用

## 参考資料

- [MySQL: SELECT DISTINCT Optimization](https://dev.mysql.com/doc/refman/8.0/en/distinct-optimization.html)
- [MySQL: PRIMARY KEY Optimization](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html)

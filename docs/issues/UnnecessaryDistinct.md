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
      "duplicates_removal": {
        "using_filesort": false,
        "table": {
          "table_name": "orders",
          "access_type": "ref",
          "used_columns": ["id", "created_at"]  // idは主キー
        }
      }
    }
  }
}
```

### 主な検出条件
1. SELECT句に主キー（通常は`id`カラム）が含まれている
2. DISTINCT演算が実行されている（`duplicates_removal`の存在）
3. 結果セットが本質的にユニークである

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

1. 意図的な重複除去
    - 非正規化されたデータ
    - 外部データソース

2. ドキュメント目的
    - 意図を明示するため
    - チーム規約

3. 将来の拡張性
    - スキーマ変更予定
    - マイグレーション中

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

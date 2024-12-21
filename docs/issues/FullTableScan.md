---
title: "Full Table Scan"
severity: "HIGH"
category: "Performance"
description: "A full table scan occurs when MySQL needs to read every row in a table to satisfy a query."
recommended: true
---

# FullTableScan

全てのレコードをスキャンする必要があり、パフォーマンスが低下する可能性がある状態を検出します。

## 説明

フルテーブルスキャンは、以下のような状況で発生します：
- WHERE句で使用されているカラムにインデックスがない
- インデックスが存在するが使用されていない
- テーブルの大部分のレコードにアクセスする必要がある

## 問題のあるクエリの例

```sql
-- インデックスのないカラムで検索
SELECT * FROM posts
WHERE view_count > 1000;

-- 関数使用によりインデックスが使用されない
SELECT * FROM users
WHERE YEAR(created_at) = 2024;

-- 広すぎる範囲での検索
SELECT * FROM logs 
WHERE status = 'active';
```

## 推奨される対策

### 1. 適切なインデックスの作成
```sql
-- 単一カラムのインデックス
ALTER TABLE posts 
ADD INDEX idx_view_count (view_count);

-- 複合インデックス
ALTER TABLE users 
ADD INDEX idx_status_created (status, created_at);
```

### 2. クエリの最適化
```sql
-- Before: インデックスが使用されない
SELECT * FROM users 
WHERE YEAR(created_at) = 2024;

-- After: インデックスが使用可能
SELECT * FROM users 
WHERE created_at >= '2024-01-01' 
  AND created_at < '2025-01-01';
```

### 3. 必要なカラムのみを選択
```sql
-- Before: 全カラムを選択
SELECT * FROM posts WHERE view_count > 1000;

-- After: 必要なカラムのみを選択
SELECT id, title, view_count 
FROM posts WHERE view_count > 1000;
```

## 例外ケース

以下の場合はフルテーブルスキャンが適切な場合があります：

1. 小規模なテーブル（数百行以下）
2. データの大部分を取得する必要がある場合
3. バッチ処理での一括処理

## パフォーマンスへの影響

### データ量による影響度
- 1万行未満: 低
- 1万〜10万行: 中
- 10万行以上: 高

### アクセス頻度による影響度
- 1時間に1回未満: 低
- 1時間に1-10回: 中
- 1時間に10回以上: 高

## デバッグ方法

### インデックスの確認
```sql
-- テーブルのインデックス一覧
SHOW INDEX FROM table_name;

-- 実行計画の確認
EXPLAIN FORMAT=JSON SELECT ...;

-- テーブル統計の確認
SHOW TABLE STATUS LIKE 'table_name';
```

## 参考

- [MySQL Indexing Best Practices](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html)
- [How to Avoid Full Table Scans](https://dev.mysql.com/doc/refman/8.0/en/table-scan-avoidance.html)

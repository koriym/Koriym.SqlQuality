---
title: "非効率なLIKE検索パターン"
severity: "HIGH"
category: "Performance"
description: "インデックスを使用できないLIKE検索パターンを検出します"
recommended: true
---

# IneffectiveLikePattern

インデックスを使用できないLIKE検索パターンを検出します。

## 説明
非効率なLIKE検索は、以下のような状況で発生します：
- 前方一致以外のワイルドカード使用（'%text'や'%text%'）
- アンダースコアワイルドカードの使用（'_text'）
- 複数のワイルドカードの組み合わせ

## EXPLAINでの検出パターン
```sql
EXPLAIN FORMAT=JSON で以下のパターンを検出:
{
  "query_block": {
    "table": {
      "attached_condition": "like_scan",    -- LIKE検索
      "possible_keys": "index_name",        -- インデックスは存在するが
      "key": null,                         -- 使用されていない
      "rows": "large_number"               -- 多数の行をスキャン
    }
  }
}
```

## 問題のあるクエリの例
```sql
-- 中間一致検索
SELECT * FROM products 
WHERE name LIKE '%keyboard%';

-- 後方一致検索
SELECT * FROM users 
WHERE email LIKE '%@example.com';

-- アンダースコア使用
SELECT * FROM files 
WHERE path LIKE '_usr/local/%';
```

## 推奨される対策

### 1. 全文検索インデックスの使用
```sql
-- 全文検索インデックスの作成
ALTER TABLE products 
ADD FULLTEXT INDEX ft_name (name);

-- クエリの変更
SELECT * FROM products 
WHERE MATCH(name) AGAINST('keyboard' IN BOOLEAN MODE);
```

### 2. 前方一致パターンの使用
```sql
-- 前方一致に変更可能な場合
SELECT * FROM users 
WHERE email LIKE 'test%';
```

### 3. N-gram検索の利用
```sql
-- N-gramパーサーを使用した全文検索
ALTER TABLE products 
ADD FULLTEXT INDEX ft_name (name) WITH PARSER ngram;

-- クエリの変更
SELECT * FROM products 
WHERE MATCH(name) AGAINST('keyboard' IN BOOLEAN MODE);
```

## 例外ケース
以下の場合は警告を無視できる可能性があります：

1. 極めて小さいテーブル
2. 検索結果が限定的な場合
3. バッチ処理での非定期的な処理

## 参考
- [MySQL: Full-Text Search Functions](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html)
- [MySQL: N-gram Full-Text Parser](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search-ngram.html)
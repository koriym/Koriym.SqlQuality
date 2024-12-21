---
title: "非効率なLIKE検索パターン"
severity: "HIGH"
category: "Performance"
description: "インデックスを使用できないLIKE検索パターンを検出します"
recommended: true
---

# IneffectiveLikePattern

## 概要
- 重要度: HIGH
- カテゴリ: Performance
- 説明: インデックスを使用できないLIKE検索パターンを検出します

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "table": {
      "attached_condition": "like_scan",    // LIKE検索の存在
      "possible_keys": "index_name",        // インデックスは存在するが
      "key": null,                          // 使用されていない
      "rows": "large_number",               // 多数の行をスキャン
      "filtered": "low_percentage",         // 低いフィルタ率
      "using_where": true                   // WHERE句での評価
    }
  }
}
```

### 主な検出条件
1. 前方一致以外のワイルドカード使用（'%text'や'%text%'）
2. アンダースコアワイルドカードの使用（'_text'）
3. 複数のワイルドカードの組み合わせ
4. LIKE条件での大文字小文字変換

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 5-50倍増加
- CPU使用率: 30-90%増加
- メモリ使用: パターンマッチングによる増加
- ディスクI/O: テーブルサイズに比例

### スケーラビリティ
- データ量に対して線形劣化（O(n)）
- パターンの複雑さに応じてCPU負荷が増加
- 同時実行数に応じてリソース競合が発生

## 例

### 問題のあるパターン

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

-- 大文字小文字を区別しない検索
SELECT * FROM customers 
WHERE LOWER(name) LIKE '%john%';

-- 複数条件の組み合わせ
SELECT * FROM articles 
WHERE title LIKE '%news%' 
   OR content LIKE '%update%';

-- 動的なパターン
SELECT * FROM logs 
WHERE message LIKE CONCAT('%', @search_term, '%');
```

### 推奨されるパターン

```sql
-- 全文検索インデックスの使用
ALTER TABLE products 
ADD FULLTEXT INDEX ft_name (name);

SELECT * FROM products 
WHERE MATCH(name) AGAINST('keyboard' IN BOOLEAN MODE);

-- 前方一致検索への変更
SELECT * FROM users 
WHERE email LIKE 'user%@example.com';

-- N-gram全文検索
ALTER TABLE articles 
ADD FULLTEXT INDEX ft_title_content (title, content) 
WITH PARSER ngram;

SELECT * FROM articles 
WHERE MATCH(title, content) 
AGAINST('+news +update' IN BOOLEAN MODE);

-- 正規化されたカラムの使用
ALTER TABLE customers 
ADD COLUMN name_search VARCHAR(255) 
GENERATED ALWAYS AS (LOWER(name)) STORED,
ADD INDEX idx_name_search (name_search);

-- ElasticSearchの統合
CREATE TABLE search_queue (
    id INT AUTO_INCREMENT,
    entity_type VARCHAR(50),
    entity_id INT,
    action ENUM('index','delete'),
    created_at TIMESTAMP,
    PRIMARY KEY (id)
);

-- パーティショニングとの組み合わせ
ALTER TABLE logs 
PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p_history VALUES LESS THAN (TO_DAYS('2024-01-01')),
    PARTITION p_current VALUES LESS THAN MAXVALUE
);
```

## 改善策の優先順位

1. 全文検索インデックスの使用
    - 難易度: 低
    - 効果: 高
    - リスク: インデックスサイズ増加
    - 必要リソース: インデックス作成時間

2. 検索エンジンの導入
    - 難易度: 高
    - 効果: 非常に高
    - リスク: システム複雑化
    - 必要リソース: 開発工数、インフラ

3. クエリパターンの最適化
    - 難易度: 中
    - 効果: 中
    - リスク: 機能制限
    - 必要リソース: 開発工数

## 無視してよい場合

1. 小規模データ
    - テーブルサイズが1,000行未満
    - 実行頻度が低い

2. 管理機能
    - バックオフィス用途
    - 同時実行が少ない

3. レポート生成
    - バッチ処理
    - 非リアルタイム要件

## トラブルシューティング

### 調査手順

1. 全文検索の設定確認
```sql
SHOW VARIABLES LIKE 'ft%';
SHOW VARIABLES LIKE 'innodb_ft%';
```

2. インデックス使用状況
```sql
SHOW INDEX FROM table_name;
EXPLAIN FORMAT=TREE SELECT ...;
```

3. クエリパフォーマンス
```sql
SET profiling = 1;
SELECT ...;
SHOW PROFILE FOR QUERY 1;
```

### 全文検索の最適化
```sql
-- 最小単語長の調整
SET GLOBAL innodb_ft_min_token_size = 2;

-- ストップワードの設定
SET GLOBAL innodb_ft_server_stopword_table = 'db_name/stopwords';

-- インデックス再構築
OPTIMIZE TABLE table_name;
```

### 一般的な誤認識パターン

1. 必要なパターンマッチング
    - 原因: 特殊な検索要件
    - 対策: キャッシュの利用

2. 一時的な全文検索
    - 原因: 一回限りの検索
    - 対策: バッチ処理での実行

## 参考資料

- [MySQL: Full-Text Search Functions](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search.html)
- [MySQL: Fine-Tuning MySQL Full-Text Search](https://dev.mysql.com/doc/refman/8.0/en/fulltext-fine-tuning.html)
- [MySQL: N-gram Full-Text Parser](https://dev.mysql.com/doc/refman/8.0/en/fulltext-search-ngram.html)

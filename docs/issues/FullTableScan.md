---
title: "Full Table Scan"
severity: "HIGH"
category: "Performance"
description: "全テーブルスキャンが発生する状況を検出します"
recommended: true
---

# FullTableScan

## 概要
- 重要度: HIGH
- カテゴリ: Performance
- 説明: 全てのレコードをスキャンする必要があり、パフォーマンスが低下する可能性がある状態を検出します

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "table": {
      "access_type": "ALL",              // フルテーブルスキャン
      "possible_keys": null,             // 使用可能なインデックスなし
      "rows": "large_number",            // 多数の行をスキャン
      "filtered": "low_percentage",      // 低いフィルタ率
      "using_where": true,              // WHERE句での評価
      "cost_info": {
        "read_cost": "high_value"       // 高い読み取りコスト
      }
    }
  }
}
```

### 主な検出条件
1. WHERE句で使用されているカラムにインデックスがない
2. インデックスが存在するが使用されていない
3. テーブルの大部分のレコードにアクセスする必要がある
4. 非選択的なインデックスのみが利用可能

## パフォーマンスへの影響

### 定量的指標
- 実行時間: 10-1000倍増加
- ディスクI/O: テーブルサイズに比例
- CPU使用率: 20-80%増加
- メモリ使用: バッファプールの占有

### スケーラビリティ
- データ量に対して線形劣化（O(n)）
- 同時実行数に応じてI/O競合が発生
- キャッシュヒット率の低下

## 例

### 問題のあるパターン

```sql
-- インデックスのないカラムで検索
SELECT * FROM posts 
WHERE view_count > 1000;

-- 広範な条件での検索
SELECT * FROM users 
WHERE status = 'active';

-- 非選択的な条件
SELECT * FROM products 
WHERE is_available = true;

-- 複数テーブルの結合
SELECT o.*, p.name 
FROM orders o 
JOIN products p ON o.product_id = p.id 
WHERE o.status = 'pending';

-- 集計を含むクエリ
SELECT category, COUNT(*) 
FROM products 
GROUP BY category;

-- 動的な条件
SELECT * FROM logs 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR);
```

### 推奨されるパターン

```sql
-- 適切なインデックスの作成
ALTER TABLE posts 
ADD INDEX idx_view_count (view_count);

-- 複合インデックスの使用
ALTER TABLE users 
ADD INDEX idx_status_created (status, created_at);

-- カバリングインデックス
ALTER TABLE products 
ADD INDEX idx_available_category (is_available, category, name, price);

-- 結合最適化
ALTER TABLE orders 
ADD INDEX idx_status_product (status, product_id);

ALTER TABLE products 
ADD INDEX idx_id_name (id, name);

-- パーティショニングの使用
ALTER TABLE logs 
PARTITION BY RANGE (TO_DAYS(created_at)) (
    PARTITION p_old VALUES LESS THAN (TO_DAYS('2024-01-01')),
    PARTITION p_current VALUES LESS THAN MAXVALUE
);

-- クエリの最適化
SELECT id, title, view_count 
FROM posts 
WHERE view_count > 1000 
LIMIT 100;
```

## 改善策の優先順位

1. インデックス作成
    - 難易度: 低
    - 効果: 高
    - リスク: ディスク使用量増加
    - 必要リソース: インデックス作成時間

2. クエリ最適化
    - 難易度: 中
    - 効果: 中～高
    - リスク: アプリケーション変更
    - 必要リソース: 開発工数

3. パーティショニング
    - 難易度: 高
    - 効果: 高
    - リスク: スキーマ変更
    - 必要リソース: 開発工数、メンテナンス

## 無視してよい場合

1. 小規模テーブル
    - 1,000行未満
    - メモリに収まる規模

2. バッチ処理
    - 日次処理
    - レポート生成

3. データ分析
    - 全件集計が必要
    - 非リアルタイム処理

## トラブルシューティング

### 調査手順

1. テーブル情報の確認
```sql
SHOW TABLE STATUS LIKE 'table_name';
SHOW INDEX FROM table_name;
```

2. クエリプロファイリング
```sql
SET profiling = 1;
SELECT ...;
SHOW PROFILE FOR QUERY 1;
```

3. バッファプール状態
```sql
SHOW ENGINE INNODB STATUS;
SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool%';
```

### パフォーマンスチューニング
```sql
-- バッファプールサイズの調整
SET GLOBAL innodb_buffer_pool_size = 4G;

-- 読み取りアヘッド設定
SET GLOBAL innodb_read_ahead_threshold = 56;

-- 統計情報の更新
ANALYZE TABLE table_name;
```

### 一般的な誤認識パターン

1. 意図的な全件取得
    - 原因: ビジネス要件
    - 対策: バッチ処理への移行

2. 一時的なフルスキャン
    - 原因: メンテナンス操作
    - 対策: 実行時間の調整

## 参考資料

- [MySQL: Optimizing SELECT Statements](https://dev.mysql.com/doc/refman/8.0/en/select-optimization.html)
- [MySQL: How to Avoid Full Table Scans](https://dev.mysql.com/doc/refman/8.0/en/table-scan-avoidance.html)
- [MySQL: InnoDB Buffer Pool](https://dev.mysql.com/doc/refman/8.0/en/innodb-buffer-pool.html)

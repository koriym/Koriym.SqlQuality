# Excessive Derived Tables

サブクエリから派生する一時テーブル（Derived Tables）の過剰な使用を検出します。

## 問題点

- 各派生テーブルは一時テーブルとしてメモリまたはディスクに保存される必要があります
- クエリの実行速度が低下する可能性があります
- メモリ使用量が増加します
- クエリの複雑性が増し、保守が困難になります

## 解決策

1. **JOINの使用を検討する**
   ```sql
   -- 改善前（派生テーブルを使用）
   SELECT * FROM 
   (SELECT id, name FROM users WHERE status = 'active') AS active_users
   WHERE active_users.name LIKE 'A%';

   -- 改善後（シンプルなJOIN）
   SELECT id, name 
   FROM users 
   WHERE status = 'active' 
   AND name LIKE 'A%';
   ```

2. **ビューの使用**
    - 頻繁に使用される派生テーブルはビューとして定義することを検討

3. **クエリの単純化**
    - 可能な限りサブクエリを避け、シンプルなJOINを使用
    - WHERE句の条件を適切に配置

## 設定

このチェックの閾値は以下の方法で調整できます：

```php
$analyzer->setThreshold('derived_table_count', 3); // 3つ以上の派生テーブルで警告
```

## 参考情報

- [MySQL: Derived Tables](https://dev.mysql.com/doc/refman/8.0/en/derived-tables.html)
- [MySQL: Optimizing Derived Tables](https://dev.mysql.com/doc/refman/8.0/en/derived-table-optimization.html)---
  title: "派生テーブルの過剰使用"
  severity: "MEDIUM"
  category: "Performance"
  description: "サブクエリや派生テーブルの過剰な使用によるパフォーマンス低下を検出します"
  recommended: true
---

# ExcessiveDerivedTables

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: クエリ内での派生テーブル（サブクエリ）の過剰な使用を検出します

## 検出パターン

### EXPLAIN出力での特徴

```json
{
    "query_block": {
        "select_id": 1,
        "cost_info": {
            "query_cost": "1543.20"
        },
        "table": {
            "table_name": "users",
            "access_type": "ALL",
            "rows_examined_per_scan": 10000,
            "attached_condition": "function_call(column_name)",
            "possible_keys": [
                "idx_column_name"
            ],
            "key": null,
            "used_key_parts": null,
            "key_length": null,
            "rows": 10000,
            "filtered": "10.00",
            "using": [
                "where"
            ],
            "cost_info": {
                "read_cost": "1443.20",
                "eval_cost": "100.00",
                "prefix_cost": "1543.20",
                "data_read_per_join": "1M"
            }
        }
    }
}```

### 主な検出条件
1. 単一クエリ内での3個以上の派生テーブル
2. 派生テーブルの入れ子構造
3. 大量のデータを含む派生テーブル
4. 一時テーブルの作成を伴う派生テーブル

## パフォーマンスへの影響

### 定量的指標
- メモリ使用: 派生テーブルごとに20-100MB増加
- 実行時間: 2-5倍の増加
- 一時テーブル数: 派生テーブルごとに1-2個
- CPU使用率: 20-50%増加

### スケーラビリティ
- データ量増加に対して指数関数的に劣化
- 同時実行数制限による並列性の低下
- メモリリソースの急速な消費

## 例

### 問題のあるパターン

```sql
SELECT * FROM 
  (SELECT * FROM 
    (SELECT id, name FROM users) AS u
    JOIN 
    (SELECT order_id, user_id FROM orders) AS o
    ON u.id = o.user_id) AS uo
JOIN 
  (SELECT product_id, order_id FROM order_items) AS oi
ON uo.order_id = oi.order_id;
```

### 推奨されるパターン

```sql
SELECT u.id, u.name, o.order_id, oi.product_id
FROM users u
JOIN orders o ON u.id = o.user_id
JOIN order_items oi ON o.order_id = oi.order_id;
```

## 改善策の優先順位

1. クエリの単純化
    - 難易度: 中
    - 効果: 高
    - リスク: 低
    - 必要リソース: 開発時間

2. ビューの活用
    - 難易度: 低
    - 効果: 中
    - リスク: 低
    - 必要リソース: 開発時間

3. インデックス最適化
    - 難易度: 中
    - 効果: 高
    - リスク: 中
    - 必要リソース: DB管理者の時間

## 無視してよい場合

1. データ分析用クエリ
    - 実行頻度が低い
    - バッチ処理での使用

2. 複雑な集計処理
    - レポート生成
    - 統計情報の計算

3. 一時的な使用
    - 開発/テスト環境
    - デバッグ目的

## トラブルシューティング

### 調査手順

1. クエリ実行計画の分析
```sql
EXPLAIN FORMAT=JSON SELECT ...;
SHOW WARNINGS;
```

2. 一時テーブル使用状況の確認
```sql
SHOW STATUS LIKE 'Created_tmp%';
```

3. メモリ使用量の監視
```sql
SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_%';
```

### パフォーマンス分析
```sql
-- クエリプロファイリング
SET profiling = 1;
SELECT ...;
SHOW PROFILE FOR QUERY 1;

-- 一時テーブルサイズの確認
SHOW STATUS LIKE 'Created_tmp_disk_tables';
```

### 一般的な誤認識パターン

1. 必要な派生テーブル
    - 原因: 複雑なビジネスロジック
    - 対策: ビューの使用

2. 最適化された派生テーブル
    - 原因: インデックス活用
    - 対策: 実行計画の確認

## 参考資料

- [MySQL: Derived Tables](https://dev.mysql.com/doc/refman/8.0/en/derived-tables.html)
- [MySQL: Optimizing Subqueries and Derived Tables](https://dev.mysql.com/doc/refman/8.0/en/subquery-optimization.html)
- [MySQL: Views and Performance](https://dev.mysql.com/doc/refman/8.0/en/views.html)

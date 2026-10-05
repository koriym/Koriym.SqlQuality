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
```
{
  "query_block": {
    "table": {
      "table_name": "posts",
      "access_type": "ALL",                  // フルテーブルスキャン
      "possible_keys": null,                 // 使用可能なインデックスなし
      "rows_examined_per_scan": "large_number", // 多数の行をスキャン
      "filtered": "low_percentage",          // 低いフィルタ率
      "attached_condition": "..."            // WHERE句の評価対象
    }
  }
}
```

`<derived2>` や `<union1,2>` のように `<` で始まる `table_name` はサブクエリ/UNION結果を保持する内部一時テーブルで、検出対象から除外されます。

### 主な検出条件
1. `access_type` が `ALL`（内部一時テーブルを除く）
2. `rows_examined_per_scan` が100件未満なら重要度 `Info`、以上なら既定（`Critical`）
3. `possible_keys` はあるが `key` が使われていなければ、Optimizer Trace が示す理由を `evidence.optimizer_trace` に記録する。`attached_condition` から等値・範囲条件のカラムが取れればインデックス作成（`index`）を提案し、取れなければ理由ごとに提案を変える
   - `cost`: レンジスキャンがコストで棄却された（棄却されたインデックス・行数・コストとテーブルスキャンの行数・コストを併記）。条件を絞るかカバリングインデックスにする（`review`）
   - `index_merge_union`: `OR` を 1 本のインデックスで処理できず、index merge union がテーブルスキャンに負けた。複合インデックスか `UNION` への書き換え（`rewrite`）
   - `join_key_only`: 結合順の先頭テーブルで、使えるキーが結合キーだけ。スキャンは想定どおりなので提案なし
   - トレースが無い、または上記に当てはまらない場合はレビュー（`review`）を提案

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

`rows_examined_per_scan` が100件未満の場合は重要度 `Info` として自動的に区別されるため、以下は目安です。

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

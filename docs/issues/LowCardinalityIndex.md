---
title: "Low Cardinality Index"
severity: "MEDIUM"
category: "Performance"
description: "カーディナリティの低いカラムへのインデックス使用を検出します"
recommended: true
---

# LowCardinalityIndex

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: 選択性の低いインデックスを使用することで、テーブルの大部分をスキャンする非効率な状態を検出します

## 検出パターン

### EXPLAIN出力での特徴
```json
{
  "query_block": {
    "ordering_operation": {
      "table": {
        "table_name": "users",
        "access_type": "ref",           // インデックスを使用
        "key": "idx_users_status",      // 低カーディナリティインデックス
        "rows_examined_per_scan": 800,  // 大量の行をスキャン
        "filtered": 100.0               // ほぼ全行が該当
      }
    }
  }
}
```

### 主な検出条件
1. `access_type`が`ref`または`range`（インデックス使用）
2. `rows_examined_per_scan` > 100 かつ `filtered` > 80%
3. テーブルの大部分（50%以上）をスキャンしている

## パフォーマンスへの影響

### 定量的指標
- 実行時間: フルテーブルスキャンとほぼ同等
- ディスクI/O: インデックス読み取り + データ読み取り（2倍）
- CPU使用率: インデックスルックアップのオーバーヘッド
- メモリ使用: インデックスバッファ + データバッファ

### スケーラビリティ
- フルテーブルスキャンより遅い可能性がある
- ランダムI/Oが発生してキャッシュ効率が悪化
- インデックスメンテナンスコスト（INSERT/UPDATE時）

## 低カーディナリティの例

### 典型的なカラム
- `status` (active/inactive) - 2値
- `gender` (male/female/other) - 3値
- `is_deleted` (0/1) - 2値
- `type` (数種類のみ) - 3-10値
- `priority` (low/medium/high) - 3値

## 例

### 問題のあるパターン

```sql
-- statusの選択性が低い（active: 80%, inactive: 20%）
SELECT * FROM users
WHERE status = 'active'
ORDER BY created_at;

-- genderでの絞り込み（50%程度）
SELECT name, email FROM users
WHERE gender = 'female';

-- is_deletedでの検索（deleted: 5%, not deleted: 95%）
SELECT * FROM posts
WHERE is_deleted = 0;

-- 複合インデックスの前方カラムが低カーディナリティ
SELECT * FROM orders
WHERE status = 'completed'  -- 80%がcompleted
  AND created_at > '2024-01-01';
```

### 推奨されるパターン

```sql
-- 高カーディナリティカラムを先頭に
CREATE INDEX idx_users_created_status ON users(created_at, status);

SELECT * FROM users
WHERE created_at > '2024-01-01'
  AND status = 'active';

-- 少数派の値のみインデックスを使用（パーシャルインデックス的）
-- deleted = 1 (5%) のみインデックス活用
SELECT * FROM posts
WHERE is_deleted = 1;

-- カバリングインデックスで効率化
CREATE INDEX idx_users_gender_name_email ON users(gender, name, email);

SELECT name, email FROM users
WHERE gender = 'female';

-- 複合インデックスの順序を最適化
CREATE INDEX idx_orders_created_status ON orders(created_at, status);

SELECT * FROM orders
WHERE created_at > '2024-01-01'
  AND status = 'completed';
```

## 改善策の優先順位

1. インデックス削除
    - 難易度: 低
    - 効果: 中（INSERT/UPDATE高速化、ディスク削減）
    - リスク: 他のクエリへの影響を確認
    - 必要リソース: インデックス削除時間

2. 複合インデックスへの変更
    - 難易度: 中
    - 効果: 高
    - リスク: 他のクエリへの影響
    - 必要リソース: インデックス再作成時間

3. クエリの書き直し
    - 難易度: 中
    - 効果: 中～高
    - リスク: アプリケーション変更
    - 必要リソース: 開発工数

4. パーティショニング
    - 難易度: 高
    - 効果: 高
    - リスク: スキーマ変更
    - 必要リソース: 大規模な開発工数

## 無視してよい場合

1. 小規模テーブル
    - 1,000行未満
    - インデックスオーバーヘッドが問題にならない

2. 少数派の値を検索
    - 5-10%程度の行のみ該当
    - 例: is_deleted = 1（削除済み5%）

3. カバリングインデックス
    - 全カラムがインデックスに含まれる
    - テーブルアクセス不要

4. 複合インデックスの後方カラム
    - 前方の高カーディナリティで絞り込み済み
    - 追加の絞り込み効果がある

## トラブルシューティング

### 調査手順

1. インデックス統計の確認
```sql
SHOW INDEX FROM table_name;

SELECT
    INDEX_NAME,
    CARDINALITY,
    TABLE_ROWS,
    CARDINALITY / TABLE_ROWS as selectivity
FROM information_schema.STATISTICS s
JOIN information_schema.TABLES t
    ON s.TABLE_SCHEMA = t.TABLE_SCHEMA
    AND s.TABLE_NAME = t.TABLE_NAME
WHERE s.TABLE_NAME = 'users';
```

2. 実際の分布確認
```sql
SELECT status, COUNT(*), COUNT(*) * 100.0 / (SELECT COUNT(*) FROM users) as percentage
FROM users
GROUP BY status;
```

3. クエリプランの比較
```sql
-- インデックス使用
EXPLAIN SELECT * FROM users WHERE status = 'active';

-- フルスキャン強制
EXPLAIN SELECT * FROM users IGNORE INDEX (idx_status) WHERE status = 'active';
```

### パフォーマンスチューニング

```sql
-- 統計情報の更新
ANALYZE TABLE users;

-- インデックスの再構築
ALTER TABLE users DROP INDEX idx_status;
ALTER TABLE users ADD INDEX idx_created_status (created_at, status);

-- オプティマイザヒント
SELECT /*+ NO_INDEX(users idx_status) */ *
FROM users
WHERE status = 'active';
```

### 一般的な誤認識パターン

1. 時系列での絞り込み併用
    - 原因: created_atとの複合条件
    - 対策: 複合インデックスの作成

2. カバリングインデックス
    - 原因: データアクセス不要
    - 対策: using_indexフラグの確認

## 参考資料

- [MySQL: How MySQL Uses Indexes](https://dev.mysql.com/doc/refman/8.0/en/mysql-indexes.html)
- [MySQL: Index Selectivity](https://dev.mysql.com/doc/refman/8.0/en/multiple-column-indexes.html)
- [High Performance MySQL: Index Selectivity and Cardinality](https://www.oreilly.com/library/view/high-performance-mysql/9781449332471/)

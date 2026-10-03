---
title: "非効率なソート操作"
severity: "MEDIUM"
category: "Performance"
description: "インデックスを使用しない非効率なソート操作を検出します"
recommended: true
---

# IneffectiveSort

## 概要
- 重要度: MEDIUM
- カテゴリ: Performance
- 説明: インデックスを使用しない、または非効率なソート操作を検出します

## 説明
非効率なソート操作は、以下のような状況で発生します：
- ORDER BY句に適したインデックスがない
- 複数カラムでのソートでインデックスの順序が異なる
- ソートカラムに関数や演算が使用されている

## EXPLAINでの検出パターン
```
{
  "query_block": {
    "ordering_operation": {
      "using_filesort": true,            // ファイルソートの使用
      "using_temporary_table": true,     // 一時テーブル経由（evidence に記録）
      "table": {
        "table_name": "orders",
        "access_type": "ALL",            // フルスキャン、または
        "rows_examined_per_scan": 2000   // 1000 行以上のインデックス参照
      }
    }
  }
}
```

ツリー内の `ordering_operation` を全て見ます。ソート対象の表は `ordering_operation` 直下の `table`、無ければその配下で最初に現れる表です（`grouping_operation` や `duplicates_removal` の下にある場合）。`using_filesort` が `false` のもの（インデックス順で読めている）は対象外です。クエリコストや読み取りサイズの閾値はありません。

### 主な検出条件
1. `using_filesort` が `true` であること
2. ソート対象の表の `access_type` が `ALL`、または `rows_examined_per_scan` が 1000 以上であること
3. 提案は常にレビュー（`review`）。WHERE の等値列に ORDER BY の列を続けた複合インデックスの検討を促し、その列で始まるインデックスが既にある場合はその名前を示します

## 問題のあるクエリの例
```sql
-- インデックスのないソート
SELECT * FROM users 
ORDER BY last_login DESC;

-- 複合ソートでインデックス順序が異なる
SELECT * FROM orders 
ORDER BY status, created_at;  -- idx_created_status が存在

-- 関数を使用したソート
SELECT * FROM products 
ORDER BY LOWER(name);
```

## 推奨される対策

### 1. 適切なインデックスの作成
```sql
-- 単一カラムのソート用インデックス
ALTER TABLE users 
ADD INDEX idx_last_login (last_login);

-- 複合インデックス（ソート順序に合わせる）
ALTER TABLE orders 
ADD INDEX idx_status_created (status, created_at);
```

### 2. ソート条件の最適化
```sql
-- Before: 関数使用
SELECT * FROM products 
ORDER BY LOWER(name);

-- After: 計算済みカラム
ALTER TABLE products 
ADD COLUMN name_lower VARCHAR(255) 
GENERATED ALWAYS AS (LOWER(name)) STORED;

ALTER TABLE products 
ADD INDEX idx_name_lower (name_lower);
```

### 3. カバリングインデックス
```sql
-- よく使用されるカラムを含むインデックス
ALTER TABLE orders 
ADD INDEX idx_covering (
  status, 
  created_at, 
  customer_id, 
  amount
);
```

## 例外ケース
以下の場合は警告を無視できる可能性があります：

1. 小規模なフルスキャン
   - `access_type` が `ALL` でも数百行程度の表で、メモリ内でソートできる（< sort_buffer_size）

2. LIMIT句での上位N件取得
   - 絞り込み後の行数が少なく、ソート対象が結果セットの10%未満の場合

3. 集計結果でのソート
   - `ORDER BY COUNT(*)` のようにインデックスでは順序を作れない場合（`TemporaryTableGrouping` と重複して報告されます）

4. バッチ処理での非定期的な処理
   - 日次バッチなど、1日1回未満の実行
   - システムの非ピーク時に実行される場合

## 参考
- [MySQL: ORDER BY Optimization](https://dev.mysql.com/doc/refman/8.0/en/order-by-optimization.html)
- [MySQL: Sorted Index Builds](https://dev.mysql.com/doc/refman/8.0/en/sorted-index-builds.html)

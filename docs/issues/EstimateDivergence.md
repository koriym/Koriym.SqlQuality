---
title: "見積もり行数の乖離"
severity: "LOW"
category: "Performance"
description: "EXPLAIN ANALYZEの見積もり行数と実測行数の乖離を検出します"
recommended: true
---

# EstimateDivergence

## 概要
- 重要度: LOW
- カテゴリ: Performance
- 説明: `EXPLAIN ANALYZE` の各実行ノードで、オプティマイザの見積もり行数（`rows`）と実測行数（`actual ... rows`）が大きく乖離している箇所を検出します。乖離は統計情報が古いことを示すことが多く、オプティマイザが誤った実行計画を選ぶ原因になります

## 検出パターン

### EXPLAIN ANALYZE出力での特徴
```text
-> Index lookup on p using idx_posts_status_created (status='published')  (cost=50 rows=50) (actual time=0.02..5.1 rows=500 loops=1)
```

### 主な検出条件
1. `EXPLAIN ANALYZE` のテキストがある（読み取り専用の SELECT のみ）
2. ノードに見積もり行数と実測行数の両方がある（`never executed` のノードは対象外）
3. 実測行数が 100 行以上
4. 見積もりと実測の比（大きい方 ÷ 小さい方。小さい方は最小 1 に丸める）が 10 倍以上

## パフォーマンスへの影響
- 乖離が大きいほど、オプティマイザが選ぶ結合順序やアクセス方法が実際のデータ分布とずれやすくなる
- 1 ノードの乖離でも、それを内側に持つ結合全体の見積もりコストが連鎖してずれる
- 多くの場合、統計情報の更新頻度不足か、直近の大量更新・削除が原因

## 例

### 問題のあるパターン
```sql
-- 大量に行が増えたのに統計情報が更新されていない posts テーブル
SELECT p.*
FROM posts p
WHERE p.status = 'published';
-- EXPLAIN ANALYZE: cost=50 rows=50 に対し実測 rows=500 loops=1
```

### 推奨されるパターン
```sql
ANALYZE TABLE posts;
```

## 改善策の優先順位
1. 対象テーブルに `ANALYZE TABLE <table>` を実行し、統計情報を更新する（難易度: 低、効果: 高）
2. 更新・削除が頻繁なテーブルは `ANALYZE TABLE` の定期実行や自動再計算の間隔を見直す（難易度: 中、効果: 中〜高）
3. 乖離が解消しない場合、偏った列にヒストグラムを作成する（`ANALYZE TABLE ... UPDATE HISTOGRAM`）（難易度: 中、効果: 分布が偏った列で高い）

## 無視してよい場合
1. 対象ノードの実測行数が小さく、結合順序への影響が無視できる
2. 直前に大量更新があり、次回の統計更新までの一時的な乖離だとわかっている

## トラブルシューティング
```sql
EXPLAIN ANALYZE SELECT ...;       -- 見積もりと実測の rows を比較
ANALYZE TABLE <table>;            -- 統計情報を更新して再実行
SHOW TABLE STATUS LIKE '<table>'; -- Update_time で最終更新を確認
```

## 参考資料
- [MySQL: ANALYZE TABLE Statement](https://dev.mysql.com/doc/refman/8.0/en/analyze-table.html)
- [MySQL: EXPLAIN ANALYZE Statement](https://dev.mysql.com/doc/refman/8.0/en/explain-analyze.html)
- [MySQL: Configuring Persistent Optimizer Statistics Parameters](https://dev.mysql.com/doc/refman/8.0/en/innodb-persistent-stats.html)

# Koriym.SqlQuality

[![Continuous Integration](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml)
[![Coding Standards](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml)

SQLファイルのパフォーマンス問題を検出し、AIを活用した最適化提案を提供するMySQLクエリ分析ツールです。

## 機能

- 一般的なパフォーマンス問題（フルテーブルスキャン、非効率な結合など）の検出
- AI技術を活用した最適化提案の提供
- 多言語出力のサポート
- Markdown形式での詳細な分析レポート生成

## 動作要件

- PHP 8.1以上
- MySQL 5.7以上、または MariaDB 10.2以上
- PDO MySQL拡張

## インストール

```bash
composer require koriym/sql-quality
```

## 使用方法

```php
<?php

namespace Koriym\SqlQuality;

use PDO;
use function dirname;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$sqlParams = require 'path/to/sql_params.php';

//return [
//    '1_full_table_scan.sql' => ['min_views' => 1000],
//    '2_filesort.sql' => ['status' => 'published', 'limit' => 10]
//];

$analyzer = new SqlFileAnalyzer(
    $pdo,
    new ExplainAnalyzer(),
    'path/to/sql_dir',
    new AIQueryAdvisor('以上の分析を日本語で記述してください。')
);

// build/sql-qualityに出力
$analyzer->analyzeSqlDirectory($sqlParams, __DIR__ . '/build/sql-quality');
```

## 分析レポート

例：

* [SQL分析サマリー](demo/build/sql-quality/summary_report.md)

アナライザーは指定された出力ディレクトリ（例：`build/sql-quality`）に2種類の分析レポートを生成します。

### 1. クエリ分析リスト

各SQLクエリの全体的な分析を表示します：

| Column | 説明 |
|--------|-------------|
| SQL File | SQLファイルの名前 |
| Cost | 推定クエリコスト |
| Level | 統計分析に基づくパフォーマンスレベル（μ = 平均、σ = 標準偏差） |
| Issues | 検出されたパフォーマンス問題 |
| Report | 詳細分析へのリンク |

例：

| SQL File | Cost | Exec Time (ms) | Level | Issues | Report |
|------------|--------|----------------|---------|---------|----------|
| 1\_full\_table\_scan.sql | 497.95 | 5.92 | Medium (μ ± σ) | FullTableScan | [詳細](1\_full\_table\_scan.md) |

### 2. オプティマイザの影響分析

MySQLのクエリオプティマイザは実行計画を自動的に最適化する重要なコンポーネントです。このツールはオプティマイザの有無による実行計画とパフォーマンスの違いを分析します。

| Column | 説明 |
|--------|-------------|
| SQL File | SQLファイルの名前 |
| Base Access | オプティマイザ無効時のアクセス方式、行数、スキャン割合 |
| Optimized Access | オプティマイザ有効時のアクセス方式、行数、スキャン割合 |
| Cost Impact | オプティマイザによるコスト削減率（負の値は改善を示す） |
| Base Issues | オプティマイザ無効時に検出された問題 |
| Plan Changes | 実行計画の詳細な変更点（フィルタリング率、コスト変更など） |

#### 例の解説

以下の例を見てみましょう：
```
Base Access: ALL, 4897 rows, 100.0%
Optimized Access: ref, using idx_posts_user_id, 4 rows, 100.0%
Cost Impact: -44.9%
```

この例では、オプティマイザが無効の場合はフルテーブルスキャンで4,897行を処理していましたが、オプティマイザが有効の場合はインデックスを使用して4行のみにアクセスするように最適化されています。コスト削減率の-44.9%は、オプティマイザによる大幅な改善を示しています。

### オプティマイザの影響について

オプティマイザによる大きなコスト削減は、現在のパフォーマンスが許容範囲内であっても、潜在的な問題を示唆している可能性があります。オプティマイザに過度に依存したクエリは、データ量の増加や統計情報の変化により、将来的にパフォーマンスが不安定になるリスクがあります。

そのため、オプティマイザの影響が大きいクエリについては、インデックス設計やクエリパターンの見直しを検討することが推奨されます。これは将来的なパフォーマンス問題を予防し、より安定したクエリ実行を確保するための重要なステップとなります。

## プロジェクト統計

サマリーレポートには以下のプロジェクト全体の統計情報が含まれます：

- 分析したSQLクエリの総数
- 平均クエリコスト
- コストの標準偏差

## 多言語サポート

SQLクエリ分析結果は、`ExplainAnalyzer`と`AIQueryAdvisor`の両方で多言語出力をサポートしています。

### ExplainAnalyzerの言語カスタマイズ

デフォルトは英語ですが、`ExplainAnalyzer`のコンストラクタでエラーメッセージをカスタマイズできます：

```php
// 日本語のエラーメッセージ
$analyzer = new ExplainAnalyzer([
    'FullTableScan' => 'フルテーブルスキャンが検出されました。',
    'IneffectiveJoin' => '非効率的な結合が検出されました。',
    'FunctionInvalidatesIndex' => '関数の使用によりインデックスが無効化されています。',
    // ... その他のメッセージ
]);

// AI Advisorと組み合わせて完全な日本語出力を実現
$analyzer = new SqlFileAnalyzer(
    $pdo,
    $analyzer,
    $sqlDirectory,
    new AIQueryAdvisor('以上の分析を日本語で記述してください。')
);
```

これにより、エラーメッセージとAI分析結果の両方を指定した言語で出力できます。

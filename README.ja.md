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

## CLI使用方法

```bash
sql-quality analyze --sql-dir=sql/ --params=params.php --format=json
sql-quality analyze --sql-dir=sql/ --params=params.php --format=markdown --output=build/sql-quality
sql-quality analyze --sql-dir=sql/ --params=params.php --fail-on=critical
sql-quality analyze --sql-dir=sql/ --params=params.php --dsn="mysql:host=localhost;dbname=mydb" --user=root --password=secret
sql-quality analyze --sql-dir=sql/ --params=params.php --lang=ja
sql-quality explain --sql-file=sql/1_full_table_scan.sql --params='{"min_views":1000}'
```

### オプション

| オプション | 説明 | デフォルト |
|--------|-------------|---------|
| `--sql-dir=DIR` | SQLファイルを含むディレクトリ（必須） | |
| `--params=FILE` | SQLパラメータ配列を返すPHPファイル（必須） | |
| `--dsn=DSN` | データベースDSN | `mysql:host=127.0.0.1;dbname=test` |
| `--user=USER` | データベースユーザー | `root` |
| `--password=PASS` | データベースパスワード | （空） |
| `--format=FORMAT` | 出力フォーマット: `json`または`markdown` | `json` |
| `--output=DIR` | Markdownレポートの出力ディレクトリ（`--format=markdown`には必須） | |
| `--fail-on=LEVEL` | `critical`・`warning`・`info`のいずれかを指定し、その水準以上の問題があれば1で終わる | なし |
| `--lang=LANG` | メッセージの言語: `en`または`ja` | `en` |

### `explain`

ディレクトリ全体ではなく、SQLファイル1本を分析し、構造化されたJSONを1つ出力します。`--dsn`・`--user`・`--password`・`--fail-on`・`--lang`は`analyze`と同じです。

| オプション | 説明 | デフォルト |
|--------|-------------|---------|
| `--sql-file=FILE` | 分析するSQLファイル（必須） | |
| `--params=JSON\|FILE` | JSONオブジェクトを直接渡すか、`analyze`の`--params`と同じ形式のPHPファイルを渡す | `{}` |

### 終了コード

| コード | 意味 |
|------|---------|
| `0` | `--fail-on`の水準に達した問題がない |
| `1` | `--fail-on`の水準に達した問題がある |
| `2` | 使い方・データベース接続・パラメータファイル・未知の`--format`の誤り。`explain`ではさらに、分析できない文（DDLなど）やMySQLが拒否する文（存在しないテーブルなど）も含む |

`analyze`では、分析できなかったファイルは終了コードを変えません。JSON出力の`skipped`に、ファイル名と理由が並びます。`explain`では振り分け先のファイルがないため、同種の失敗は終了コード`2`で終了し、理由を標準エラー出力に出します。

### JSON Schema

両コマンドのJSON出力は[`schema/`](schema/)のスキーマに適合します。`analyze --format=json`の出力は[`analyze-report.schema.json`](schema/analyze-report.schema.json)に、`explain`の出力は[`explain-report.schema.json`](schema/explain-report.schema.json)に適合します。エージェントは出力を使う前にこのスキーマで検証できます。

## Claude Code Skills

[Claude Code](https://claude.ai/code)ユーザー向けに、SQL最適化の自動化スキルが利用可能です：

### インストール

**マーケットプレイスから:**
```bash
# マーケットプレイスを追加
/plugin marketplace add koriym/Koriym.SqlQuality

# プラグインをインストール（3つのスキルすべてが含まれます）
/plugin install sql-quality@sql-quality
```

**プロジェクト開発者向け:**
このプロジェクトフォルダを信頼すると、Claude Codeが自動的にマーケットプレイスの追加とプラグインの有効化を促します（`.claude/settings.json`で設定済み）。

### 使用方法

```bash
# SQLファイルを分析（CI対応）
/sql-quality-check tests/sql tests/params/sql_params.php

# 段階的な測定で自動修正
/sql-quality-fix tests/sql tests/params/sql_params.php

# SQLファイルからパラメータバインディングを生成
/sql-params-generate tests/sql
```

### 機能

これらのAI駆動型スキルは以下を実現します：
- パフォーマンス問題の検出（FullTableScan、IneffectiveJoin、CartesianProduct、EstimateDivergenceなど）
- 問題のあるSQLパターンの書き換え（カラムの関数使用、暗黙的な型変換など）
- インデックスの作成とリアルタイムでの影響測定
- 効果のないインデックスの自動ロールバック
- コスト削減を含む詳細な改善レポートの生成

詳細なドキュメントは`skills/*/SKILL.md`を参照してください。

## 分析レポート

例：

* [SQL Analysis Summary](demo/build/sql-quality/summary_report.md)

アナライザーは指定された出力ディレクトリ（例：`build/sql-quality`）に2種類の分析レポートを生成します。

### 1. クエリ分析リスト (Query Analysis)

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

### 2. オプティマイザの影響分析 (Queries with Optimizer Impact)

MySQLのクエリオプティマイザ（Query Optimizer）は実行計画を自動的に最適化する重要なコンポーネントです。SQLやインデックスの設計が最適でない場合でも、オプティマイザが実行時に改善を試みます。

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

| SQL File | Base Access | Optimized Access | Cost Impact | Base Issues | Plan Changes |
|:----------|:------------|:----------------|:------------|:------------|:-------------|
| 11\_nested\_loop.sql | ALL, 4897 rows, 100.0% | ALL, 1000 rows, 10.0% → ref, using idx\_posts\_user\_id, 4 rows, 100.0% | -44.9% | FullTableScan | - |

この例では、オプティマイザが無効の場合はフルテーブルスキャンで4,897行を処理していましたが、オプティマイザが有効の場合はインデックスを使用して4行のみにアクセスするように最適化されています。コスト削減率の-44.9%は、オプティマイザによる大幅な改善を示しています。

### オプティマイザの影響について

オプティマイザはパフォーマンスを改善しますが、他方でオプティマイザへの依存が潜在的な問題を隠してしまう可能性があります。またデータ量の増加や統計情報の変化により、将来的にパフォーマンスが不安定になるリスクもあります。このような問題を実行計画とパフォーマンスをオプティマイザの有無で比較することで、早期に発見し、適切な対策を講じることが本機能の目的です。

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

## Detectorを書く

Detectorは`src/Detector/`に置く`DetectorInterface`の実装です。`detect()`は`QueryContext`を受け取り、該当したテーブルや実行計画のノードごとに`Finding`を1つ返します。

```php
final class FullTableScanDetector implements DetectorInterface
{
    /** @return list<Finding> */
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($table['access_type'] === 'ALL') {
                $findings[] = new Finding(array_intersect_key($table, array_flip(['table_name', 'rows_examined_per_scan', 'possible_keys', 'key'])));
            }
        }

        return $findings;
    }
}
```

`QueryContext`には、パラメータを埋めた`sql`、`explain`（`EXPLAIN FORMAT=JSON`）、`explainAnalyze`、`warnings`（`SHOW WARNINGS`）、テーブルごとの`schema`（information_schema）、`optimizerTrace`（デフォルトオプティマイザの`EXPLAIN`に対する`information_schema.OPTIMIZER_TRACE`の抜粋。取得しない・読めない・切り詰められたなど利用できなければ`null`）が入っています。`tables()`、`tableAccesses()`、`nestedLoops()`、`warningsWithCode()`、`indexColumns()`、`aliases()`、`schemaFor()`、`columnType()`、`primaryKeyColumns()`、`optimizerTraceFor()`で読み出します。

`Finding::$evidence`にはDetectorが判定の根拠にした値を入れます。実行計画から取った値はEXPLAINのキー名のまま、1つのテーブルについてのfindingなら`table_name`も含めます。この値はissueの`evidence`としてそのまま報告されます。`severity`、`confidence`、`suggestion`は省略でき、指定するとそのtypeの既定値に代わって使われます。

テストには`tests/fixtures/`に記録済みの実行計画を使います。`Fixture::load('1_full_table_scan.sql')`が`tests/sql/1_full_table_scan.sql`の`QueryContext`を返し、`tests/fixtures/expected.php`には各fixtureが出すべきtypeが並んでいます。Detectorを登録するには、`ExplainAnalyzer`のコンストラクタに追加し、`src/Types.php`の`WarningType`と`WarningMessages`にtypeを、`ExplainAnalyzer::DEFAULT_MESSAGES`に既定メッセージを、`docs/issues/`にページを足します。

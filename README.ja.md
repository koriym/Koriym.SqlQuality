# Koriym.SqlQuality

[![Continuous Integration](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/continuous-integration.yml)
[![Coding Standards](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml/badge.svg)](https://github.com/koriym/Koriym.SqlQuality/actions/workflows/coding-standards.yml)

コーディングエージェントとCIのためのMySQLクエリ分析ツールです。ツールが測り、エージェントが判断します。

エージェントは書き換えやインデックスを提案できますが、SQLを読むだけでは、書き換えた文が同じ行を返すか、MySQLがそのインデックスを使うか、問題が今はオプティマイザに隠れているだけなのかは分かりません。`sql-quality`は実際のデータベースでそれを確かめ、JSONで返します。

- **analyze** / **explain**：`EXPLAIN FORMAT=JSON`、`EXPLAIN ANALYZE`、`SHOW WARNINGS`、スキーマ、オプティマイザトレースを18のDetectorにかけます。issueには安定したid、severity、根拠（evidence）、そして該当すればインデックスや書き換えの提案が付きます。
- **compare**：元のSELECTと書き換えたSELECTを実行し、同じ行を返すかを確かめます。
- **try-index**：候補のインデックスを*不可視*で追加し、このセッションでだけ使わせて計測し、削除します。
- **--baseline**：新しいissueと既知のissueを区別し、CIは変更が持ち込んだ問題でだけ失敗します。

各文はオプティマイザの機能を切った状態でもEXPLAINされます。`optimizer_impact.hidden_issues`は、オプティマイザが今は回避している問題の一覧です。統計情報やデータ量が変われば表に出てきます。

## 動作要件

- PHP 8.1以上、PDO MySQL
- MySQL 8.0.18以上（`EXPLAIN ANALYZE`のため。`try-index`は不可視インデックスを使います）

## インストール

```bash
composer require --dev koriym/sql-quality
```

## コマンド

接続先は`SQL_QUALITY_DSN`・`SQL_QUALITY_USER`・`SQL_QUALITY_PASSWORD`、または`--dsn`・`--user`・`--password`で指定します（既定は`mysql:host=127.0.0.1;dbname=test`、`root`、パスワードなし）。コマンドラインのパスワードはシェルの履歴やエージェントの記録に残るので、環境変数を使ってください。

標準出力にはJSONのレポートだけを出し、エラーは標準エラー出力に出します。

### analyze

```bash
vendor/bin/sql-quality analyze --sql-dir=sql/ --params=sql/params.json --fail-on=critical
```

ディレクトリ内の`*.sql`をすべて分析します。`--params`はファイル名からパラメータへの対応です（`.json`、または同じ配列を返すPHPファイル）。対応のないファイルはパラメータなしで実行し、`:name`のプレースホルダが埋まらないファイルは`skipped`に`UnboundParameter: name, ...`として載ります。

```json
{
  "1_full_table_scan.sql": {"min_views": 1000},
  "15_listed_parameters.sql": {"names": ["Alice", "Bob"]}
}
```

`--baseline=FILE`に以前の`analyze`のレポートを渡すと、`baseline.new`・`resolved`・`persisting`・`unverified`（以前のレポートにあって今回分析できなかったファイル）が加わります。baselineがあるとき、`--fail-on`は新しいissueだけを数えます。

```bash
vendor/bin/sql-quality analyze --sql-dir=sql/ --params=sql/params.json > baseline.json   # mainブランチで
vendor/bin/sql-quality analyze --sql-dir=sql/ --params=sql/params.json --baseline=baseline.json --fail-on=warning
```

### explain

```bash
vendor/bin/sql-quality explain --sql-file=sql/1_full_table_scan.sql --params='{"min_views":1000}'
vendor/bin/sql-quality explain --sql="SELECT * FROM posts WHERE user_id = :id" --params='{"id":1}'
echo "SELECT * FROM posts" | vendor/bin/sql-quality explain --sql-file=-
```

文を1つ、ファイル・直書き・標準入力のいずれかで受け取ります。出力はissueとオプティマイザの影響だけの要約です。`--detail=full`でEXPLAIN JSON、`EXPLAIN ANALYZE`、`SHOW WARNINGS`、スキーマ、オプティマイザトレースが加わります。

issueの`id`は種類と場所です（`FullTableScan@posts`、`FunctionInvalidatesIndex@posts.created_at`）。行数やコストを含まないので、問題が残っている間は実行のたびに同じ値になります。同じ場所の2件目以降には`#2`、`#3`を付け、名前に含まれる`@`、`.`、`#`、`\`はバックスラッシュでエスケープします。

### compare

```bash
vendor/bin/sql-quality compare \
  --before=sql/3_function_on_indexed_column.sql \
  --after-sql="SELECT * FROM posts WHERE created_at >= :d AND created_at < :d + INTERVAL 1 DAY" \
  --params='{"d":"2024-01-01"}'
```

両方のSELECTを読み取り専用セッションで実行し、結果を比べます。`equivalent`（同じ列、同じ行の多重集合）、`same_order`、行数とチェックサム、コストと時間の変化、書き換えで解消したissueと新たに出たissueを返します。`both_empty: true`はどちらも1行も返さなかったことを示し、その一致は何も証明しません。行を返すパラメータを選んでください。`--before-params`・`--after-params`は片側だけ`--params`を上書きします。等価なら0、そうでなければ1で終わります。値はドライバが返すPHPの型ごと比べ（`5`と`'5'`は別物）、浮動小数点数は有効数字17桁で比べるため、`DOUBLE`の`SUM()`の足す順番が変わるだけの書き換えも等価でないと判定されることがあります。アプリケーションから見える値が変わるためです。

### try-index

```bash
vendor/bin/sql-quality try-index --sql-file=sql/1_full_table_scan.sql --params=sql/params.json \
  --index="CREATE INDEX idx_posts_view_count ON posts (view_count)"
```

`--index`には提案の`ddl`か、短い`posts(view_count, created_at)`を渡します。インデックスを`INVISIBLE, ALGORITHM=INPLACE, LOCK=NONE`で追加し、このセッションでだけ`use_invisible_indexes=on`にして計測し、削除します。実行計画がそれを使ったか（`used`）、効果があったか（`effective`：使われ、コストが`--min-improvement`以上、既定5%下がった）を返します。効果があれば0、なければ1で終わります。

`--keep`を付けると、効果のあったインデックスを残して可視にします。効果のないものはどちらにせよ削除します。インデックスの作成はテーブル全体を読み、ALTER権限が必要です。開発用のデータベースで実行してください。

- ALTERとDROPは、テーブルに開いているトランザクションがあるとメタデータロックを待ち、その間ほかの文もその後ろに並びます。そのためセッションの`lock_wait_timeout`を10秒に下げており、混んでいるテーブルではテーブルを止めずに試行が失敗します（終了コード2）。
- インデックスを追加する前に、それを消す`DROP INDEX`文を標準エラー出力に出します。試行の途中でプロセスが強制終了されると`INVISIBLE`のインデックスが残ることがあります。その文を実行するか、`information_schema.statistics`で`sqlq_trial_%`や指定した名前を探してください。
- 別のスキーマのテーブル（`reporting.posts(view_count)`）は拒否します。`--dsn`でそのスキーマに接続し、テーブル名だけを指定してください。接続先データベース名で修飾したテーブル（`FROM app.posts`に対する提案の`ddl`）は受け付けます。
- 文が読むテーブルに不可視インデックスがすでにあると拒否します（`InvisibleIndexesPresent`）。`use_invisible_indexes=on`でそれも有効になり、効果を試行したインデックスに帰せないためです。

### 終了コード

| コード | 意味 |
|------|---------|
| `0` | チェックに通った |
| `1` | `--fail-on`に達したissueがある、書き換えが等価でない、またはインデックスに効果がない |
| `2` | 実行できなかった：使い方、接続、パラメータ、MySQLが拒否した文。標準エラー出力に例外名が出る（例：`UnboundParameter: target_date`） |

### JSON Schema

各コマンドの出力は[`schema/`](schema/)のスキーマ（`analyze-report`・`explain-report`・`compare-report`・`try-index-report`）に従います。

## Claude Codeプラグイン

コマンドを使う3つのスキルを同梱しています。

```bash
/plugin marketplace add koriym/Koriym.SqlQuality
/plugin install sql-quality@sql-quality
```

| スキル | 内容 |
|-------|--------------|
| `/sql-quality-check sql/ sql/params.json [baseline.json]` | issueをCI向けに報告する。何も変更しない |
| `/sql-quality-fix sql/ sql/params.json [--keep-indexes]` | `compare`が等価と示した書き換えだけを適用し、`try-index`が効果を示したインデックスだけを勧める |
| `/sql-params-generate sql/` | データベースに実在する値からパラメータファイルを書く |

このリポジトリでは、フォルダを信頼すると`.claude/settings.json`がプラグインを有効にします。

## ライブラリとして使う

```php
use Koriym\SqlQuality\JsonReportGenerator;
use Koriym\SqlQuality\SqlFileAnalyzer;

$analyzer = new SqlFileAnalyzer($pdo, sqlDir: __DIR__ . '/sql');
$run = $analyzer->analyzeDirectory(['1_full_table_scan.sql' => ['min_views' => 1000]]);
$report = (new JsonReportGenerator())->generate($run);

$result = $analyzer->analyzeSql('SELECT * FROM posts WHERE user_id = :id', ['id' => 1]);
```

`compare`・`try-index`・`--baseline`の中身は`RewriteComparator`・`IndexTrial`・`Baseline`です。

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

`QueryContext`には、パラメータを埋めた`sql`、`explain`（`EXPLAIN FORMAT=JSON`）、`explainAnalyze`、`warnings`（`SHOW WARNINGS`）、テーブルごとの`schema`（information_schema。キーは`posts`、文がスキーマ名で修飾していれば`reporting.posts`）、`optimizerTrace`（デフォルトオプティマイザの`EXPLAIN`に対する`information_schema.OPTIMIZER_TRACE`の抜粋。取得しない・読めない・切り詰められたなど利用できなければ`null`）が入っています。`tables()`、`tableAccesses()`、`nestedLoops()`、`warningsWithCode()`、`indexColumns()`、`aliases()`、`schemaFor()`、`columnType()`、`primaryKeyColumns()`、`optimizerTraceFor()`で読み出します。

`Finding::$evidence`にはDetectorが判定の根拠にした値を入れます。実行計画から取った値はEXPLAINのキー名のまま、1つのテーブルについてのfindingなら`table_name`を、1つの列についてなら`column`も含めます。issueのidはこれらから作られます。この値はissueの`evidence`としてそのまま報告されます。`severity`、`confidence`、`suggestion`は省略でき、指定するとそのtypeの既定値に代わって使われます。

テストには`tests/fixtures/`に記録済みの実行計画を使います。`Fixture::load('1_full_table_scan.sql')`が`tests/sql/1_full_table_scan.sql`の`QueryContext`を返し、`tests/fixtures/expected.php`には各fixtureが出すべきtypeが並んでいます。Detectorを登録するには、`ExplainAnalyzer`のコンストラクタに追加し、`src/Types.php`の`WarningType`と、`schema/analyze-report.schema.json`・`schema/explain-report.schema.json`の`type`のenumにtypeを、`ExplainAnalyzer::MESSAGES`にメッセージを、`docs/issues/`にページを足します。

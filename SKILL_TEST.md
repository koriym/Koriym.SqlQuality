# SQL Quality Skill テスト手順

## 前提条件

- MySQL 8.0.18以上が起動している
- テストDB `test` に `tests/sql/schema.sql` を投入済み
- 接続情報は環境変数で渡す（未設定なら下の既定値）

```bash
export SQL_QUALITY_DSN='mysql:host=127.0.0.1;port=3306;dbname=test'
export SQL_QUALITY_USER=root
export SQL_QUALITY_PASSWORD=
```

## テスト1: /sql-quality-check

**目的:** 分析だけを行い、CI向けに報告する

```
/sql-quality-check tests/sql tests/params/sql_params.php
```

**期待する結果:**
- `vendor/bin/sql-quality`（なければ`bin/sql-quality`）の`analyze --fail-on=critical`が実行される
- issueがidと重要度つきで一覧になり、`optimizer_impact.hidden_issues`も別枠で示される
- `skipped`のファイル（DDL、`UnboundParameter`など）が理由つきで示される
- ファイルもスキーマも変更しない

## テスト2: /sql-params-generate

**目的:** プレースホルダのパラメータをDBの実データから作る

```
/sql-params-generate tests/sql
```

**期待する結果:**
- `analyze`の`skipped`から`UnboundParameter`のファイルとパラメータ名が特定される
- DBに実在する値でJSONのパラメータファイルが書かれる
- 書いたファイルで`analyze`し直すと`UnboundParameter`が残らない

## テスト3: /sql-quality-fix

**目的:** 検証できた修正だけを適用する

```
/sql-quality-fix tests/sql tests/params/sql_params.php
```

**期待する結果:**
1. `build/sql-quality/baseline.json`が作られる
2. 書き換えは`compare`で`equivalent: true`・`both_empty: false`だったものだけが適用される
3. インデックスは`try-index`で試され、`--keep-indexes`がなければDBに残らない
4. 最後の`analyze --baseline`で`baseline.new`が空になっている
5. `build/sql-quality/fix-report.md`の数値がすべてツールの出力に由来する

## 確認ポイント

- [ ] `SHOW INDEX`で、`sqlq_trial_`や`try-index`で試したインデックスが残っていない（`--keep-indexes`なし）
- [ ] エージェントが`CREATE INDEX`・`DROP INDEX`を直接実行していない
- [ ] 終了コード2のとき、標準エラー出力の理由が報告されている

## 注意

- 開発用のDBで実行してください。`try-index`は実際にインデックスを作ります（不可視で、テーブル全体を読みます）
- 本番データには実行しないでください

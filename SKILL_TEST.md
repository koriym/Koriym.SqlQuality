# SQL Quality Skill テスト手順

## 前提条件

✅ **準備済み:**
- MySQL起動中（malt使用）
- テストDB `test` 作成済み
- スキーマ・テストデータ投入済み

**DB接続情報:**
- DSN: `mysql:host=127.0.0.1;port=3306;dbname=test`
- User: `root`
- Password: (empty)

## テスト1: /sql-quality-check

**目的:** 分析のみ行い、レポートを出力する（CIで使用）

**実行:**
```
/sql-quality-check tests/sql tests/params/sql_params.php
```

**期待する結果:**
- 分析結果がテーブル形式で表示される
- **31個の問題**が検出される（FullTableScan, IneffectiveSort, UnnecessaryDistinct, LowCardinalityIndex等）
- 平均コスト: 約850
- ファイルの変更は行わない
- 重大な問題があれば警告を表示

---

## テスト2: /sql-params-generate

**目的:** SQLファイルからパラメータファイルを生成する

**実行:**
```
/sql-params-generate tests/sql
```

**期待する結果:**
- DBに接続してスキーマ情報を取得
- tests/sql/*.sql 内のプレースホルダーを検出
- パラメータファイルを生成

---

## テスト3: /sql-quality-fix

**目的:** SQLを修正し、インデックスを作成し、効果を計測する

**実行:**
```
/sql-quality-fix tests/sql tests/params/sql_params.php
```

**期待する結果:**
1. 初期分析が行われる
2. SQLファイルが修正される（問題がある場合）
3. インデックスが作成される
4. 効果が計測され、効果がないインデックスはDROPされる
5. 段階的な改善レポートが表示される

---

## 確認ポイント

- [ ] CLIコマンド `bin/sql-quality` が実行できるか
- [ ] スキルの指示をClaude Codeが理解できるか
- [ ] 分析結果が正しくパースできるか
- [ ] ファイル編集が適切に行われるか
- [ ] エラー時の動作

## 注意

- このテストは開発環境で実行してください
- テスト用DBを使用してください
- 本番データには絶対に実行しないでください

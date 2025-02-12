<?php

namespace Koriym\SqlQuality;

use PDO;
use function dir;
use function dirname;

require dirname(__DIR__) . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$sqlParams = require dirname(__DIR__) . '/tests/params/sql_params.php';

$analyzer = new SqlFileAnalyzer(
    $pdo,
    new ExplainAnalyzer([
        'ExcessiveDerivedTables'    => '派生テーブルの過度な使用が検出されました。',
        'FunctionInvalidatesIndex'  => '関数の使用によりインデックスが無効化されています。',
        'FullTableScan'            => 'フルテーブルスキャンが検出されました。',
        'ImplicitTypeConversion'   => '暗黙的な型変換が検出されました。',
        'IneffectiveJoin'          => '非効率的な結合が検出されました。',
        'IneffectiveLikePattern'   => '非効率的なLIKEパターンが検出されました。',
        'IneffectiveRangeScan'     => '非効率的な範囲スキャンが検出されました。範囲条件が多すぎる行をカバーしています。',
        'IneffectiveSort'          => '非効率的なソート操作が検出されました。',
        'IneffectiveUnion'         => '非効率的なUNIONの使用が検出されました。一時テーブルが使用される可能性があります。',
        'LowCardinalityIndex'      => 'カーディナリティの低い列にインデックスが設定されています。これは非効率的なスキャンの原因となる可能性があります。',
        'MultiTableUpdate'         => '複数テーブルの更新が検出されました。これは重いテーブルロックを引き起こす可能性があります。',
        'TemporaryTableGrouping'   => 'グループ化のために一時テーブルが必要です。',
        'UnnecessaryDistinct'      => 'すでにユニークな列に対する不必要なDISTINCTが検出されました。'
    ]),
    dirname(__DIR__) . '/tests/sql',
    new AIQueryAdvisor('以上の分析を日本語で記述してください。'), // 'Please describe the above analysis in YOURLANGUAGE'.
    new OptimizerSettings($pdo)
);
// Output to build/sql-quality
$analyzer->analyzeSqlDirectory($sqlParams, __DIR__ . '/build/sql-quality');

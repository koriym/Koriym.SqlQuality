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
    new ExplainAnalyzer(),
    dirname(__DIR__) . '/tests/sql',
    new AIQueryAdvisor('以上の分析を日本語で記述してください。'), // 'Please describe the above analysis in YOURLANGUAGE'.
    new OptimizerSettings($pdo)
);
// Output to build/sql-quality
$analyzer->analyzeSqlDirectory($sqlParams, __DIR__ . '/build/sql-quality');

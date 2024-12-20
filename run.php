<?php

namespace Koriym\SqlQuality;

use PDO;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);

$sqlParams = require __DIR__ . '/tests/params/sql_params.php';

$analyzer = new SqlFileAnalyzer(
    $pdo,
    new ExplainAnalyzer(),
    __DIR__ . '/tests/sql',
    new AIQueryAdvisor('以上の分析を日本語で記述してください')
);
$results = $analyzer->analyzeSQLFiles($sqlParams);
echo $analyzer->getFormattedResults($results);


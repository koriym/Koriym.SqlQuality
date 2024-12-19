<?php

namespace Koriym\SqlQuality;

use PDO;
use const _PHPStan_7dc4cc62e\__;

require __DIR__ . '/vendor/autoload.php';

$pdo = new PDO('mysql:host=127.0.0.1;dbname=test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
]);
require __DIR__ . '/src/SqlFileAnalyzer.php';
require __DIR__ . '/src/ExplainAnalyzer.php';

$sqlParams = require __DIR__ . '/tests/params/sql_params.php';
$analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/tests/sql');
$results = $analyzer->analyzeSQLFiles($sqlParams);
echo $analyzer->getFormattedResults($results);

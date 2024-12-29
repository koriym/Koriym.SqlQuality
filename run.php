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

$statistics = new QueryStatisticsCalculator();
$classifier = new StatisticalQueryLevelClassifier();
$reportGenerator = new MarkdownSummaryReportGenerator($statistics, $classifier);

// クエリ結果を統計計算に渡す
$statistics->calculate($results);

$reportPath = __DIR__ . '/tests/sql/ai_prompts/summary_report.md';
$reportGenerator->saveSummaryReport('summary_report.md');

echo "Summary report saved to: {$reportPath}\n";

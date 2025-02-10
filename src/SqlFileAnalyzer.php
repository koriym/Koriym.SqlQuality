<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;

use function array_keys;
use function array_map;
use function array_pop;
use function array_shift;
use function array_sum;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_null;
use function is_string;
use function json_decode;
use function microtime;
use function mkdir;
use function pathinfo;
use function preg_replace;
use function printf;
use function sort;

use const PATHINFO_FILENAME;

/**
 * @psalm-import-type SqlParams from Types
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type DetectedWarning from Types
 * @psalm-import-type SchemaInfo from Types
 * @psalm-import-type ShowWarning from Types
 */
final class SqlFileAnalyzer
{
    private const TRIAL_COUNT = 10;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ExplainAnalyzer $analyzer,
        private readonly string $sqlDir,
        private readonly AIQueryAdvisor $aiAdvisor,
    ) {
    }

    /**
     * Analyzes the SQL files contained in the specified parameters, generates statistical analyses,
     * outputs detailed Markdown reports for each SQL file, and creates a summary report.
     *
     * @param array<string, mixed> $sqlParams An associative array of SQL parameters, such as file paths and configurations, to be analyzed.
     * @param string               $outputDir The directory where output reports, including individual Markdown files and a summary report, will be saved.
     *
     *               example: $sqlParams [
     *                  1_full_table_scan.sql' => ['min_views' => 1000],
     *                  2_filesort.sql' => ['status' => 'published', 'limit' => 10],
     *               ]
     *
     * @return array<string, array<string, mixed>> Returns an associative array where the keys are SQL file paths and the values are their respective analysis results, including AI suggestions and identified issues.
     */
    public function analyzeSqlDirectory(array $sqlParams, string $outputDir): array
    {
        // 1) すべての SQL を分析
        $results = $this->analyzeSQLFiles($sqlParams, $outputDir);

        // 2) 解析結果を統計計算にかける
        $statistics = new QueryStatisticsCalculator();
        $statistics->calculate($results);

        // 3) レベル分類クラスとレポート生成クラスを用意
        $classifier = new StatisticalQueryLevelClassifier();
        $reportGenerator = new MarkdownSummaryReportGenerator($statistics, $classifier);

        // 4) それぞれの SQL に対応する Markdown レポート(= AI prompt)を出力
        //    → ここでは SqlFileAnalyzer::savePromptToMarkdown を呼ぶ想定
        //    （すでに内部で呼んでいる場合は省略可）
        foreach ($results as $sqlFile => $analysisResult) {
            $this->savePromptToMarkdown(
                $sqlFile,
                $analysisResult['ai_suggestions'],
                $analysisResult['issues'],
                $outputDir,
            );
        }

        // 5) まとめレポート（summary_report.md）を出力
        //    デフォルトのファイル名を summary_report.md とする
        $reportGenerator->saveSummaryReport($outputDir, 'summary_report.md');

        return $results;
    }

    /**
     * @param SqlParams $sqlParams
     *
     * @return array<string, AnalysisResult>
     *
     * @throws RuntimeException
     */
    public function analyzeSQLFiles(array $sqlParams, string $outputDir): array
    {
        $results = [];
        foreach ($sqlParams as $sqlFile => $params) {
            try {
                $result = $this->analyze($sqlFile, $params, $outputDir, $results);
                $results[$sqlFile] = $result;
                $cost = $result['cost'];
                printf("✔️Analyzed: %4d: %s\n", $cost, $sqlFile);
            } catch (RuntimeException $e) {
                printf("⚠️Skipped: %s: %s\n", $sqlFile, $e->getMessage());
            }
        }

        return $results;
    }

    /** @param ExplainResult $explainResult */
    private function calculateCost(array $explainResult): float
    {
        $cost = $this->analyzer->calculateQueryCost($explainResult);

        return $cost['total_cost'];
    }

    private function readSqlFile(string $filename): string
    {
        $path = $this->sqlDir . '/' . $filename;
        if (! file_exists($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read SQL file: {$path}");
        }

        return $content;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException
     */
    private function executeExplain(string $sql, array $params): array
    {
        $interpolatedSql = $this->interpolateQuery($sql, $params);
        $stmt = $this->pdo->query('EXPLAIN FORMAT=JSON ' . $interpolatedSql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to execute EXPLAIN query');
        }

        /** @var array|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            throw new RuntimeException('Failed to get EXPLAIN result');
        }

        $explainJson = $result['EXPLAIN'] ?? '';
        if ($explainJson === '') {
            throw new RuntimeException('Empty EXPLAIN result');
        }

        /** @var array<array-key, mixed> $explainData */
        $explainData = json_decode($explainJson, true);

        return $explainData;
    }

    /**
     * @return list<ShowWarning>
     *
     * @throws RuntimeException
     */
    private function getWarnings(): array
    {
        $stmt = $this->pdo->query('SHOW WARNINGS');
        if ($stmt === false) {
            throw new RuntimeException('Failed to execute SHOW WARNINGS');
        }

        /** @var list<ShowWarning> $warnings */
        $warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $warnings;
    }

    /** @param array<string, mixed> $params */
    private function interpolateQuery(string $sql, array $params): string
    {
        $keys = array_map(
            static fn (string $key): string => "/:$key/",
            array_keys($params),
        );

        $values = array_map(
            function (mixed $value): string {
                if (is_array($value) && count($value) === 0) {
                    throw new RuntimeException('Empty list given');
                }

                return match (true) {
                    is_null($value) => 'NULL',
                    is_bool($value) => $value ? '1' : '0',
                    is_string($value) => $this->pdo->quote($value),
                    is_array($value) => implode(',', array_map(fn ($v) => is_string($v) ? $this->pdo->quote($v) : (string) $v, $value)),
                    default => (string) $value
                };
            },
            array_values($params),
        );

        return (string) preg_replace($keys, $values, $sql);
    }

    /** @return array<string, SchemaInfo> */
    private function getSchemaInfo(string $sql): array
    {
        $tableNames = $this->aiAdvisor->extractTableNames($sql);
        $schemaInfo = [];
        foreach ($tableNames as $tableName) {
            $schemaInfo[$tableName] = $this->aiAdvisor->extractSchemaInfo($this->pdo, $tableName);
        }

        return $schemaInfo;
    }

    /**
     * @param list<DetectedWarning> $issues
     *
     * @throws RuntimeException
     */
    private function savePromptToMarkdown(string $sqlFile, string $prompt, array $issues, string $outputDir): void
    {
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true)) {
            throw new RuntimeException("Failed to create directory: {$outputDir}");
        }

        $promptFile = $outputDir . '/' . pathinfo($sqlFile, PATHINFO_FILENAME) . '.md';
        if (file_put_contents($promptFile, $prompt) === false) {
            throw new RuntimeException("Failed to save prompt to file: {$promptFile}");
        }
    }

    /** @param array<string, AnalysisResult> $results */
    public function generateSummaryReport(array $results, string $outputDir): string
    {
        $statistics = new QueryStatisticsCalculator();
        $classifier = new StatisticalQueryLevelClassifier();
        $reportGenerator = new MarkdownSummaryReportGenerator($statistics, $classifier);

        return $reportGenerator->generate($results);
    }

    /**
     * @param array<string, mixed>          $params
     * @param array<string, AnalysisResult> $results
     *
     * @return array{
           issues: list<DetectedWarning>,
           explain_result: ExplainResult,
           ai_suggestions: string,
           cost: float,
           execution_time: float
       }
     */
    public function analyze(
        string $sqlFile,
        array $params,
        string $outputDir,
        array $results
    ): array {
        $sql = $this->readSqlFile($sqlFile);
        $executionTime = $this->getExecutedTime($sql, $params);
        /** @var ExplainResult $explainResult */
        $explainResult = $this->executeExplain($sql, $params);
        /** @var list<array{Level: string, Code: int, Message: string}> $warnings */
        $warnings = $this->getWarnings();
        /** @var list<DetectedWarning> $issues */
        $issues = $this->analyzer->analyze($explainResult, $warnings);
        /** @var array<string, SchemaInfo> $schemaInfo */
        $schemaInfo = $this->getSchemaInfo($sql);
        $cost = $this->calculateCost($explainResult);

        $aiPrompt = $this->aiAdvisor->generatePrompt(
            $sqlFile,
            $sql,
            $explainResult,
            $issues,
            $schemaInfo,
        );

        $this->savePromptToMarkdown($sqlFile, $aiPrompt, $issues, $outputDir);

        return [
            'issues' => $issues,
            'explain_result' => $explainResult,
            'ai_suggestions' => $aiPrompt,
            'cost' => $cost,
            'execution_time' => $executionTime,
        ];
    }

    /** @param array<string, mixed> $params */
    public function getExecutedTime(string $sql, array $params): float
    {
        $interpolatedSql = $this->interpolateQuery($sql, $params);
        // warm up the cache
        $this->pdo->query($interpolatedSql);
        $stmt = $this->pdo->query($interpolatedSql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to execute SQL query:' . $interpolatedSql);
        }

        $executionTimes = [];
        for ($i = 0; $i < self::TRIAL_COUNT; $i++) {
            $startTime = microtime(true);
            $stmt = $this->pdo->query($interpolatedSql);
            // Fetch all results to ensure:
            // 1. The query is fully executed
            // 2. The result set is fully retrieved
            // 3. The database cache is properly warmed up
            // This helps in getting consistent execution times across trials
            $stmt->fetchAll();
            $endTime = microtime(true);
            $executionTimes[] = $endTime - $startTime;
        }

        sort($executionTimes);
        array_shift($executionTimes); // Remove the minimum value
        array_pop($executionTimes);   // Remove the maximum value

        return array_sum($executionTimes) / count($executionTimes);
    }
}

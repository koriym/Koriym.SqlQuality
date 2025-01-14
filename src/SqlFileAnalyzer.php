<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use RuntimeException;

use function array_any;
use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function is_array;
use function is_bool;
use function is_dir;
use function is_null;
use function is_string;
use function json_decode;
use function mkdir;
use function pathinfo;
use function preg_replace;
use function str_contains;
use function str_replace;
use function trim;

use const PATHINFO_FILENAME;

/**
 * @psalm-import-type DetectedWarning from ExplainAnalyzer
 * @psalm-import-type SchemaInfo from AIQueryAdvisor
 * @psalm-type SqlParams = array<string, array<string, mixed>>
 * @psalm-type ExplainResult = array{
 *   query_block: array{
 *     select_id: int,
 *     table?: array{
 *       table_name: string,
 *       access_type: string,
 *       possible_keys?: string|null,
 *       key?: string|null,
 *       rows: int,
 *       filtered: float
 *     },
 *     ordering_operation?: array{
 *       using_filesort: bool,
 *       table: array
 *     },
 *     grouping_operation?: array{
 *       using_temporary_table: bool,
 *       using_filesort: bool,
 *       table: array
 *     }
 *   }
 * }
 * @psalm-type AnalysisResult = array{
 *   issues: list<DetectedWarning>,
 *   explain_result: ExplainResult,
 *   ai_suggestions: string,
 *   cost: float
 * }
 * @psalm-type AnalysisResults = array<string, AnalysisResult>
 * @psalm-type ShowWarnings = list<array{
 *   Level: string,
 *   Code: int,
 *   Message: string
 * }>
 */
final class SqlFileAnalyzer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ExplainAnalyzer $analyzer,
        private readonly string $sqlDir,
        private readonly AIQueryAdvisor $aiAdvisor,
    ) {
    }

    /**
     * @param SqlParams $sqlParams
     *
     * @return AnalysisResults
     *
     * @throws RuntimeException
     */
    public function analyzeSQLFiles(array $sqlParams): array
    {
        $results = [];
        foreach ($sqlParams as $sqlFile => $params) {
            $sql = $this->readSqlFile($sqlFile);
            $explainResult = $this->executeExplain($sql, $params);
            $warnings = $this->getWarnings();
            $issues = $this->analyzer->analyze($explainResult, $warnings);
            $schemaInfo = $this->getSchemaInfo($sql);
            $cost = $this->calculateCost($explainResult);
            $aiPrompt = $this->aiAdvisor->generatePrompt(
                $sqlFile,        // ファイル名を渡す
                $sql,           // SQL内容を渡す
                $explainResult,
                $issues,
                $schemaInfo,
            );
            $this->savePromptToMarkdown($sqlFile, $aiPrompt, $issues);

            $results[(string) $sqlFile] = [
                'issues' => $issues,
                'explain_result' => $explainResult,
                'ai_suggestions' => $aiPrompt,
                'cost' => $cost,
            ];
        }

        return $results;
    }

    private function calculateCost(array $explainResult): float
    {
        $cost = $this->analyzer->calculateQueryCost($explainResult);

        return (float) $cost['total_cost']; // If you intend it to be float
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
     * @return ExplainResult
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

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if (! $result) {
            throw new RuntimeException('Failed to get EXPLAIN result');
        }

        $explainJson = $result['EXPLAIN'] ?? '';
        if (empty($explainJson)) {
            throw new RuntimeException('Empty EXPLAIN result');
        }

        $explainData = json_decode($explainJson, true);
        if (! is_array($explainData)) {
            throw new RuntimeException('Failed to decode EXPLAIN result');
        }

        /** @var ExplainResult */
        return $explainData;
    }

    /**
     * @return ShowWarnings
     *
     * @throws RuntimeException
     */
    private function getWarnings(): array
    {
        $stmt = $this->pdo->query('SHOW WARNINGS');
        if ($stmt === false) {
            throw new RuntimeException('Failed to execute SHOW WARNINGS');
        }

        /** @var ShowWarnings */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
                return match (true) {
                    is_null($value) => 'NULL',
                    is_bool($value) => $value ? '1' : '0',
                    is_string($value) => $this->pdo->quote($value),
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

    private function savePromptToMarkdown(string $sqlFile, string $prompt, array $issues): void
    {
        $promptDir = $this->sqlDir . '/ai_prompts'; // または設定で指定されたパス
        if (! is_dir($promptDir) && ! mkdir($promptDir, 0777, true)) {
            throw new RuntimeException("Failed to create directory: {$promptDir}");
        }

        $promptFile = $promptDir . '/' . pathinfo($sqlFile, PATHINFO_FILENAME) . '.md';
        $content = $prompt;
        if (file_put_contents($promptFile, $content) === false) {
            throw new RuntimeException("Failed to save prompt to file: {$promptFile}");
        }
    }

    public function generateSummaryReport(array $results): string
    {
        $statistics = new QueryStatisticsCalculator();
        $classifier = new StatisticalQueryLevelClassifier();
        $reportGenerator = new MarkdownSummaryReportGenerator($statistics, $classifier);

        return $reportGenerator->generate($results);
    }

    private function simplifyIssueMessage(string $message): string
    {
        return trim(str_replace([
            'operation detected',
            'required for grouping',
            'detected',
            '.',
        ], '', $message));
    }
}

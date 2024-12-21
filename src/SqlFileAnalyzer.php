<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use RuntimeException;

use function array_keys;
use function array_map;
use function array_values;
use function file_exists;
use function file_get_contents;
use function is_array;
use function is_bool;
use function is_null;
use function is_string;
use function json_decode;
use function preg_replace;
use function str_repeat;

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
 *   ai_suggestions: string
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

            $results[$sqlFile] = [
                'issues' => $issues,
                'explain_result' => $explainResult,
                'ai_suggestions' => $this->aiAdvisor->generatePrompt(
                    $sql,
                    $explainResult,
                    $issues,
                    $schemaInfo,
                ),
            ];
        }

        return $results;
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

    /** @param AnalysisResults $results */
    public function getFormattedResults(array $results): string
    {
        $output = '';
        foreach ($results as $sqlFile => $result) {
            $output .= "Results for {$sqlFile}:\n";
            $output .= $this->analyzer->formatResults($result['issues']);
            $output .= "\nAI Analysis Suggestions:\n";
            $output .= $result['ai_suggestions'] . "\n";
            $output .= str_repeat('=', 80) . "\n";
        }

        return $output;
    }
}

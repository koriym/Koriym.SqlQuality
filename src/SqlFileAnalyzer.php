<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;
use RuntimeException;

use function array_keys;
use function array_map;
use function array_values;
use function file_exists;
use function file_get_contents;
use function is_bool;
use function is_null;
use function is_string;
use function json_decode;
use function json_last_error;
use function json_last_error_msg;
use function preg_replace;
use function str_repeat;

use const JSON_ERROR_NONE;

final class SqlFileAnalyzer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ExplainAnalyzer $analyzer,
        private readonly string $sqlDir,
        private readonly AIQueryAdvisor|null $aiAdvisor = null,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $sqlParams
     *
     * @return array<string, array{
     *     issues: array<int, array<string, string>>,
     *     explain_result: array<string, mixed>
     * }>
     */
    public function analyzeSQLFiles(array $sqlParams): array
    {
        $results = [];
        foreach ($sqlParams as $sqlFile => $params) {
            $this->analyzer->setSqlFile($sqlFile);
            $explainResult = $this->executeExplain(
                $this->readSQLFile($sqlFile),
                $params,
            );

            $results[$sqlFile] = [
                'issues' => $this->analyzer->analyze($explainResult),
                'explain_result' => $explainResult,
            ];
        }

        return $results;
    }

    /**
     * AIアドバイザーが設定されている場合のみプロンプトを生成
     */
    public function generateAIPrompt(string $sqlFile, array $results): string|null
    {
        if ($this->aiAdvisor === null) {
            return null;
        }

        $sql = $this->readSQLFile($sqlFile);
        if (! isset($results[$sqlFile])) {
            throw new RuntimeException("Analysis results not found for file: {$sqlFile}");
        }

        $result = $results[$sqlFile];

        // スキーマ情報を収集
        $schemaInfo = [];
        $tables = $this->aiAdvisor->extractTableNames($sql);
        foreach ($tables as $table) {
            $schemaInfo[$table] = $this->aiAdvisor->extractSchemaInfo($this->pdo, $table);
        }

        return $this->aiAdvisor->generatePrompt(
            $sql,
            $result['explain_result'],
            $result['issues'],
            $schemaInfo,
        );
    }

    private function readSQLFile(string $sqlFile): string
    {
        $path = $this->sqlDir . '/' . $sqlFile;
        if (! file_exists($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }

        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("Failed to read SQL file: {$path}");
        }

        return $sql;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function executeExplain(string $sql, array $params): array
    {
        try {
            $interpolatedSql = $this->interpolateQuery($sql, $params);
            $stmt = $this->pdo->query('EXPLAIN FORMAT=JSON ' . $interpolatedSql);
            if ($stmt === false) {
                throw new RuntimeException('Failed to execute EXPLAIN query');
            }

            $explainResult = $stmt->fetch(PDO::FETCH_ASSOC);
            if (! $explainResult) {
                throw new RuntimeException('Failed to get EXPLAIN result');
            }

            $explainJson = $explainResult['EXPLAIN'] ?? '';
            if (empty($explainJson)) {
                throw new RuntimeException('Empty EXPLAIN result');
            }

            $explainData = json_decode($explainJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode EXPLAIN result: ' . json_last_error_msg());
            }

            return $explainData;
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to execute EXPLAIN: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $params */
    private function interpolateQuery(string $sql, array $params): string
    {
        $keys = array_map(
            static fn (string $key): string => "/:$key/",
            array_keys($params),
        );
        $values = array_map(
            fn (mixed $value): string => match (true) {
                is_null($value) => 'NULL',
                is_bool($value) => $value ? '1' : '0',
                is_string($value) => $this->pdo->quote($value),
                default => (string) $value
            },
            array_values($params),
        );

        return preg_replace($keys, $values, $sql);
    }

    public function getFormattedResults(array $results): string
    {
        $output = '';
        foreach ($results as $sqlFile => $result) {
            $this->analyzer->setSqlFile($sqlFile);
            $output .= $this->analyzer->getFormattedIssues($result['issues']);

            // AIアドバイスがある場合は追加
            if ($this->aiAdvisor !== null) {
                $prompt = $this->generateAIPrompt($sqlFile, $results);
                if ($prompt !== null) {
                    $output .= "\nAI Analysis Suggestions:\n";
                    $output .= $prompt;
                }
            }

            $output .= "\n" . str_repeat('=', 80) . "\n";
        }

        return $output;
    }
}

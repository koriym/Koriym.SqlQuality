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

use const JSON_ERROR_NONE;

final class SqlFileAnalyzer
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ExplainAnalyzer $analyzer,
        private readonly string $sqlDir,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $sqlParams
     *
     * @return array<string, array<int, array<string, string>>>
     */
    public function analyzeSQLFiles(array $sqlParams): array
    {
        $results = [];
        foreach ($sqlParams as $sqlFile => $params) {
            $this->analyzer->setSqlFile($sqlFile);
            $results[$sqlFile] = $this->analyzeSQLFile($sqlFile, $params);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, string>>
     */
    private function analyzeSQLFile(string $sqlFile, array $params): array
    {
        $sql = $this->readSQLFile($sqlFile);
        $explainResult = $this->executeExplain($sql, $params);

        return $this->analyzer->analyze($explainResult);
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
            $explainResult = $stmt->fetch(PDO::FETCH_ASSOC);
            if (! $explainResult) {
                throw new RuntimeException('Failed to get EXPLAIN result');
            }

            $explainData = json_decode($explainResult['EXPLAIN'], true);
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
        foreach ($results as $sqlFile => $issues) {
            $this->analyzer->setSqlFile($sqlFile);
            $output .= $this->analyzer->getFormattedIssues($issues);
        }

        return $output;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;
use RuntimeException;

use function array_unique;
use function json_encode;
use function preg_match;
use function preg_match_all;

use const JSON_PRETTY_PRINT;

class AIQueryAdvisor
{
    public function __construct(
        private readonly string $instruction = 'Please provide your analysis in English.',
    ) {
    }

    public function generatePrompt(
        string $sql,
        array $explainResult,
        array $issues,
        array|null $schemaInfo = null,
    ): string {
        $context = $this->formatContext($sql, $explainResult, $issues, $schemaInfo);

        return <<<PROMPT
As an expert database performance consultant, please analyze this SQL query and its EXPLAIN results. 
Provide specific, actionable recommendations for optimization.

SQL Context:
{$context}

Please provide:
1. A concise summary of the performance issues identified
2. Specific, detailed recommendations for optimization, including:
   - Index suggestions with exact column combinations
   - Query restructuring proposals
   - Schema optimization ideas if applicable
3. Example SQL for implementing the suggested changes
4. Expected benefits and potential trade-offs of each suggestion

Focus on practical, implementable solutions that would have the highest impact on performance.

{$this->instruction}
PROMPT;
    }

    private function formatContext(
        string $sql,
        array $explainResult,
        array $issues,
        array|null $schemaInfo,
    ): string {
        $context = "Original SQL:\n{$sql}\n\n";

        if ($schemaInfo !== null) {
            $context .= "Schema Information:\n";
            $context .= json_encode($schemaInfo, JSON_PRETTY_PRINT) . "\n\n";
        }

        $context .= "EXPLAIN Results:\n";
        $context .= json_encode($explainResult, JSON_PRETTY_PRINT) . "\n\n";

        $context .= "Identified Issues:\n";
        $context .= json_encode($issues, JSON_PRETTY_PRINT) . "\n";

        return $context;
    }

    /** @throws RuntimeException */
    public function extractSchemaInfo(PDO $pdo, string $tableName): array
    {
        if (! $this->isValidTableName($tableName)) {
            throw new RuntimeException('Invalid table name');
        }

        try {
            $quotedTable = $pdo->quote($tableName);

            return [
                'columns' => $this->getColumnInfo($pdo, $quotedTable),
                'indexes' => $this->getIndexInfo($pdo, $quotedTable),
                'status' => $this->getTableStatus($pdo, $quotedTable),
            ];
        } catch (PDOException $e) {
            throw new RuntimeException('Failed to extract schema info: ' . $e->getMessage());
        }
    }

    public function extractTableNames(string $sql): array
    {
        preg_match_all('/(?:FROM|JOIN)\s+`?(\w+)`?/i', $sql, $matches);

        return array_unique($matches[1] ?? []);
    }

    private function isValidTableName(string $tableName): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $tableName);
    }

    private function getColumnInfo(PDO $pdo, string $quotedTable): array
    {
        $sql = "
            SELECT 
                column_name,
                data_type,
                column_type,
                is_nullable,
                column_key,
                column_default,
                extra
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = {$quotedTable}
            ORDER BY ordinal_position
        ";

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getIndexInfo(PDO $pdo, string $quotedTable): array
    {
        $sql = "
            SELECT 
                index_name,
                column_name,
                non_unique,
                seq_in_index,
                cardinality
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
            AND table_name = {$quotedTable}
            ORDER BY index_name, seq_in_index
        ";

        return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function getTableStatus(PDO $pdo, string $quotedTable): array
    {
        $sql = "
            SELECT 
                table_rows,
                data_length,
                index_length,
                auto_increment,
                create_time,
                update_time
            FROM information_schema.tables 
            WHERE table_schema = DATABASE()
            AND table_name = {$quotedTable}
        ";

        return $pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}

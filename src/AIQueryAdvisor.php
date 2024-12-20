<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;

use function array_unique;
use function json_encode;
use function preg_match_all;

use const JSON_PRETTY_PRINT;

class AIQueryAdvisor
{
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
PROMPT;
    }

    private function formatContext(
        string $sql,
        array $explainResult,
        array $issues,
        array|null $schemaInfo,
    ): string {
        $context = "Original SQL:\n{$sql}\n\n";

        // Add schema information if available
        if ($schemaInfo !== null) {
            $context .= "Schema Information:\n";
            $context .= json_encode($schemaInfo, JSON_PRETTY_PRINT) . "\n\n";
        }

        // Add EXPLAIN results
        $context .= "EXPLAIN Results:\n";
        $context .= json_encode($explainResult, JSON_PRETTY_PRINT) . "\n\n";

        // Add identified issues
        $context .= "Identified Issues:\n";
        $context .= json_encode($issues, JSON_PRETTY_PRINT) . "\n";

        return $context;
    }

    public function extractSchemaInfo(PDO $pdo, string $tableName): array
    {
        $tableInfo = [];

        // Get column information
        $columns = $pdo->query("SHOW COLUMNS FROM {$tableName}")->fetchAll(PDO::FETCH_ASSOC);
        $tableInfo['columns'] = $columns;

        // Get existing indexes
        $indexes = $pdo->query("SHOW INDEXES FROM {$tableName}")->fetchAll(PDO::FETCH_ASSOC);
        $tableInfo['indexes'] = $indexes;

        // Get table status (size, rows, etc.)
        $status = $pdo->query("SHOW TABLE STATUS LIKE '{$tableName}'")->fetch(PDO::FETCH_ASSOC);
        $tableInfo['status'] = $status;

        return $tableInfo;
    }

    public function extractTableNames(string $sql): array
    {
        preg_match_all('/(?:FROM|JOIN)\s+`?(\w+)`?/i', $sql, $matches);

        return array_unique($matches[1] ?? []);
    }
}

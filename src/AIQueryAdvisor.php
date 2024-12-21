<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use RuntimeException;

/**
 * @psalm-import-type DetectedWarning from ExplainAnalyzer
 * @psalm-import-type ExplainResult from SqlFileAnalyzer
 *
 * @psalm-type SchemaColumn = array{
 *   column_name: string,
 *   data_type: string,
 *   column_type: string,
 *   is_nullable: string,
 *   column_key: string,
 *   column_default: string|null,
 *   extra: string
 * }
 *
 * @psalm-type SchemaIndex = array{
 *   index_name: string,
 *   column_name: string,
 *   non_unique: string,
 *   seq_in_index: string,
 *   cardinality: string|null
 * }
 *
 * @psalm-type TableStatus = array{
 *   table_rows: int|null,
 *   data_length: int|null,
 *   index_length: int|null,
 *   auto_increment: int|null,
 *   create_time: string|null,
 *   update_time: string|null
 * }
 *
 * @psalm-type SchemaInfo = array{
 *   columns: list<SchemaColumn>,
 *   indexes: list<SchemaIndex>,
 *   status: TableStatus
 * }
 */
final class AIQueryAdvisor
{
    public function __construct(
        private readonly string $instruction = 'Please provide your analysis in English.',
    ) {
    }

    /**
     * @param ExplainResult $explainResult
     * @param list<DetectedWarning> $issues
     * @param array<string, SchemaInfo>|null $schemaInfo
     */
    public function generatePrompt(
        string $sql,
        array $explainResult,
        array $issues,
        ?array $schemaInfo = null,
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

    /**
     * @param ExplainResult $explainResult
     * @param list<DetectedWarning> $issues
     * @param array<string, SchemaInfo>|null $schemaInfo
     */
    private function formatContext(
        string $sql,
        array $explainResult,
        array $issues,
        ?array $schemaInfo,
    ): string {
        $context = "Original SQL:\n{$sql}\n\n";

        if ($schemaInfo !== null) {
            $context .= "Schema Information:\n";
            $context .= json_encode($schemaInfo, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n\n";
        }

        $context .= "EXPLAIN Results:\n";
        $context .= json_encode($explainResult, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n\n";

        $context .= "Identified Issues:\n";
        $context .= json_encode($issues, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

        return $context;
    }

    /**
     * @return SchemaInfo
     * @throws RuntimeException
     */
    public function extractSchemaInfo(PDO $pdo, string $tableName): array
    {
        if (!$this->isValidTableName($tableName)) {
            throw new RuntimeException('Invalid table name');
        }

        try {
            $quotedTable = $pdo->quote($tableName);

            return [
                'columns' => $this->getColumnInfo($pdo, $quotedTable),
                'indexes' => $this->getIndexInfo($pdo, $quotedTable),
                'status' => $this->getTableStatus($pdo, $quotedTable),
            ];
        } catch (RuntimeException $e) {
            throw new RuntimeException('Failed to extract schema info: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<string>
     */
    public function extractTableNames(string $sql): array
    {
        // SQLコメントを削除
        $sql = preg_replace('/--.*$/m', '', $sql);

        // キーワードの後にあるテーブル名を抽出
        // AS/ON/WHEREなどの後のテーブル名は除外
        if (preg_match_all('/(?:FROM|JOIN)\s+(?:`?(\w+)`?(?:\s+AS)?\s+[a-zA-Z]|`?(\w+)`?(?:\s|$))/i', $sql, $matches)) {
            $tables = array_filter(array_merge($matches[1], $matches[2]));
            return array_unique(array_values($tables));
        }

        return [];
    }

    private function isValidTableName(string $tableName): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $tableName);
    }

    /**
     * @return list<SchemaColumn>
     * @throws RuntimeException
     */
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

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to get column information');
        }

        /** @var list<SchemaColumn> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<SchemaIndex>
     * @throws RuntimeException
     */
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

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to get index information');
        }

        /** @var list<SchemaIndex> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return TableStatus
     * @throws RuntimeException
     */
    private function getTableStatus(PDO $pdo, string $quotedTable): array
    {
        $sql = "
        SELECT 
            TABLE_ROWS as table_rows,
            DATA_LENGTH as data_length,
            INDEX_LENGTH as index_length,
            AUTO_INCREMENT as auto_increment,
            CREATE_TIME as create_time,
            UPDATE_TIME as update_time
        FROM information_schema.tables 
        WHERE table_schema = DATABASE()
        AND table_name = {$quotedTable}
    ";

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to get table status');
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            throw new RuntimeException('No table status found');
        }

        /** @var TableStatus */
        return $result;
    }
}

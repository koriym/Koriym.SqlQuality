<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use RuntimeException;

use function array_filter;
use function array_map;
use function array_merge;
use function array_unique;
use function array_values;
use function implode;
use function json_encode;
use function max;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function sprintf;

use const JSON_THROW_ON_ERROR;

/**
 * @psalm-import-type DetectedWarning from Types
 * @psalm-import-type SchemaInfo from Types
 * @psalm-import-type SchemaColumn from Types
 * @psalm-import-type SchemaIndex from Types
 * @psalm-import-type TableStatus from Types
 * @psalm-import-type ExplainResult from Types
 */
final class AIQueryAdvisor
{
    private const ISSUE_DOC_URL = 'https://koriym.github.io/Koriym.SqlQuality/issues';

    private const ANALYSIS_TEMPLATE = <<<'TEMPLATE'
# SQL Performance Analysis
- **SQL File:** `%s`
- **Cost:** %s

## SQL
```sql
%s
```

## Detected Issues
%s

## Explain Tree
```
%s
```

## AI Prompt
%s

### Schema
%s

### EXPLAIN Results
%s
%s
TEMPLATE;

    private const AI_PROMPT_TEMPLATE = <<<'TEMPLATE'
Based on the provided MySQL table schemas and EXPLAIN results, please provide:

1. Brief Assessment
   - Summarize key performance bottlenecks identified in the EXPLAIN output
   - Highlight any concerning access patterns (table scans, suboptimal joins)
   - Note any missing or underutilized indexes

2. Specific Optimization Recommendations
   a) Index Improvements
      - New indexes to create (with exact column combinations)
      - Existing indexes to modify or remove
      - Coverage analysis for frequently accessed columns
   b) Query Optimization
      - Join order and method improvements
      - Subquery optimization opportunities
      - Filtering and sorting efficiency
   c) Schema Enhancements (if applicable)
      - Table structure improvements
      - Partitioning considerations
      - Data type optimizations

3. Implementation Details
   For each recommendation:
     - Exact SQL statements for implementation
     - Estimated impact on query performance
     - Potential risks or trade-offs
     - Implementation priority (High/Medium/Low)

4. Additional Considerations
   - Impact on existing indexes and storage requirements
   - Effects on write performance
   - Maintenance requirements
   - Backup/restore implications

Please focus on practical, high-impact improvements that can be implemented with minimal risk.
TEMPLATE;

    public function __construct(
        private readonly string $instruction = 'Please provide your analysis in English.',
    ) {
    }

    /**
     * @param ExplainResult                  $explainResult
     * @param list<DetectedWarning>          $issues
     * @param array<string, SchemaInfo>|null $schemaInfo
     */
    public function generatePrompt(
        string $sqlFile,
        string $sql,
        array $explainResult,
        array $issues,
        array|null $schemaInfo = null,
    ): string {
        return sprintf(
            self::ANALYSIS_TEMPLATE,
            $sqlFile,
            $this->extractCost($explainResult),
            $sql,
            $this->formatIssues($issues),
            $this->generateExplainTree($explainResult),
            self::AI_PROMPT_TEMPLATE,
            $this->formatSchemaInfo($schemaInfo),
            $this->formatExplainResult($explainResult),
            $this->instruction,
        );
    }

    /** @param ExplainResult $explainResult */
    private function extractCost(array $explainResult): string
    {
        // クエリブロックレベルのコスト
        /** @var float|null $queryCost */
        $queryCost = $explainResult['query_block']['cost_info']['query_cost'] ?? null;

        // 実行計画の詳細コスト
        $planCost = 0.0;

        /** @var array $queryBlock */
        $queryBlock = $explainResult['query_block'];
        if (isset($queryBlock)) {
            // テーブルスキャンのコスト
            if (isset($queryBlock['table']['cost_info'])) {
                $tableCost = $queryBlock['table']['cost_info'];
                $planCost += ($tableCost['read_cost'] ?? 0) + ($tableCost['eval_cost'] ?? 0);
            }

            // ソート操作のコスト
            if (isset($queryBlock['ordering_operation']['cost_info'])) {
                $sortCost = $queryBlock['ordering_operation']['cost_info'];
                $planCost += $sortCost['sort_cost'] ?? 0;
            }

            // 一時テーブルのコスト
            if (isset($queryBlock['grouping_operation']['cost_info'])) {
                $groupCost = $queryBlock['grouping_operation']['cost_info'];
                $planCost += $groupCost['tmp_table_cost'] ?? 0;
            }
        }

        $finalCost = max($queryCost ?? 0, $planCost);

        return $finalCost > 0 ? (string) $finalCost : 'N/A';
    }

    /** @param list<DetectedWarning> $issues */
    private function formatIssues(array $issues): string
    {
        return implode(
            "\n",
            array_map(
                static fn (array $issue): string => sprintf(
                    '- %s [Learn more](%s/%s)',
                    $issue['message'],
                    self::ISSUE_DOC_URL,
                    $issue['type'],
                ),
                $issues,
            ),
        );
    }

    private function generateExplainTree(array $explainResult): string
    {
        $parser = new ExplainParser();
        $visualizer = new ExplainTreeVisualizer();
        $tree = $parser->parse(json_encode($explainResult, JSON_THROW_ON_ERROR));

        return $visualizer->toString($tree);
    }

    /** @param array<string, SchemaInfo>|null $schemaInfo */
    private function formatSchemaInfo(array|null $schemaInfo): string
    {
        if (empty($schemaInfo)) {
            return 'N/A';
        }

        return json_encode($schemaInfo, JSON_THROW_ON_ERROR);
    }

    /** @param ExplainResult $explainResult */
    private function formatExplainResult(array $explainResult): string
    {
        return json_encode($explainResult, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    public function extractTableNames(string $sql): array
    {
        // SQLコメントを削除
        $sql = preg_replace('/--.*$/m', '', $sql);

        // キーワードの後にあるテーブル名を抽出
        // AS/ON/WHEREなどの後のテーブル名は除外
        if (preg_match_all('/(?:FROM|JOIN)\s+(?:`?(\w+)`?(?:\s+AS)?\s+[a-zA-Z]|`?(\w+)`?(?:\s|$))/i', $sql, $matches)) {
            $tables = array_filter(array_merge($matches[1], $matches[2]));

            return array_values(array_unique($tables));
        }

        return [];
    }

    private function isValidTableName(string $tableName): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9_]+$/', $tableName);
    }

    /**
     * @return SchemaInfo
     *
     * @throws RuntimeException
     */
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
        } catch (RuntimeException $e) {
            throw new RuntimeException('Failed to extract schema info: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return list<SchemaColumn>
     *
     * @throws RuntimeException
     */
    private function getColumnInfo(PDO $pdo, string $quotedTable): array
    {
        $sql = <<<SQL
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
SQL;

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to get column information');
        }

        /** @var list<SchemaColumn> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<SchemaIndex>
     *
     * @throws RuntimeException
     */
    private function getIndexInfo(PDO $pdo, string $quotedTable): array
    {
        $sql = <<<SQL
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
SQL;

        $stmt = $pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to get index information');
        }

        /** @var list<SchemaIndex> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return TableStatus
     *
     * @throws RuntimeException
     */
    private function getTableStatus(PDO $pdo, string $quotedTable): array
    {
        $sql = <<<SQL
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
SQL;

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

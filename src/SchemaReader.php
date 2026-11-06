<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;

use function array_unique;
use function array_values;
use function explode;
use function preg_match;
use function preg_match_all;
use function preg_replace;
use function trim;

/**
 * Reads the information_schema rows of the tables a statement references.
 *
 * @psalm-import-type SchemaInfo from Types
 * @psalm-import-type SchemaColumn from Types
 * @psalm-import-type SchemaIndex from Types
 * @psalm-import-type TableStatus from Types
 */
final class SchemaReader
{
    private const TABLE_LIST = '/\b(?:FROM|JOIN|UPDATE)\s+(`?\w+`?(?:\s+(?:AS\s+)?(?!(?:WHERE|ON|SET|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|USING|GROUP|ORDER|LIMIT|HAVING|UNION|WINDOW|FOR|LOCK|INTO|PARTITION|USE|IGNORE|FORCE)\b)`?\w+`?)?(?:\s*,\s*`?\w+`?(?:\s+(?:AS\s+)?(?!(?:WHERE|ON|SET|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|USING|GROUP|ORDER|LIMIT|HAVING|UNION|WINDOW|FOR|LOCK|INTO|PARTITION|USE|IGNORE|FORCE)\b)`?\w+`?)?)*)/i';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, SchemaInfo>
     *
     * @throws RuntimeException
     */
    public function forSql(string $sql, QuoteRules $rules = new QuoteRules()): array
    {
        $schema = [];
        foreach (self::tableNames($sql, $rules) as $tableName) {
            $schema[$tableName] = $this->forTable($tableName);
        }

        return $schema;
    }

    /** @return list<string> */
    public static function tableNames(string $sql, QuoteRules $rules = new QuoteRules()): array
    {
        // quote-aware: a "--" inside a string literal is not a comment
        $sql = SqlSafetyClassifier::stripComments($sql, $rules);
        preg_match_all(self::TABLE_LIST, $sql, $matches);

        $tables = [];
        foreach ($matches[1] as $list) {
            foreach (explode(',', $list) as $reference) {
                $tables[] = trim((string) preg_replace('/\s.*/s', '', trim($reference)), '`');
            }
        }

        return array_values(array_unique($tables));
    }

    /**
     * @return SchemaInfo
     *
     * @throws RuntimeException
     */
    public function forTable(string $tableName): array
    {
        if (! preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
            throw new RuntimeException('Invalid table name');
        }

        $quotedTable = $this->pdo->quote($tableName);

        return [
            'columns' => $this->columns($quotedTable),
            'indexes' => $this->indexes($quotedTable),
            'status' => $this->status($quotedTable),
        ];
    }

    /**
     * @return list<SchemaColumn>
     *
     * @throws RuntimeException
     */
    private function columns(string $quotedTable): array
    {
        // With derived_merge=off (the no-optimizer pass) information_schema returns these headers lower-cased; the aliases pin the SchemaColumn case.
        $sql = <<<SQL
            SELECT
                column_name AS COLUMN_NAME,
                data_type AS DATA_TYPE,
                column_type AS COLUMN_TYPE,
                is_nullable AS IS_NULLABLE,
                column_key AS COLUMN_KEY,
                column_default AS COLUMN_DEFAULT,
                extra AS EXTRA
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
            AND table_name = {$quotedTable}
            ORDER BY ordinal_position
SQL;

        $stmt = $this->pdo->query($sql);
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
    private function indexes(string $quotedTable): array
    {
        // Aliases pin the header case; see columns().
        $sql = <<<SQL
            SELECT
                index_name AS INDEX_NAME,
                column_name AS COLUMN_NAME,
                non_unique AS NON_UNIQUE,
                seq_in_index AS SEQ_IN_INDEX,
                cardinality AS CARDINALITY
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
            AND table_name = {$quotedTable}
            ORDER BY index_name, seq_in_index
SQL;

        $stmt = $this->pdo->query($sql);
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
    private function status(string $quotedTable): array
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

        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to get table status');
        }

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            // a CTE name or another reference with no information_schema.tables row
            return [
                'table_rows' => null,
                'data_length' => null,
                'index_length' => null,
                'auto_increment' => null,
                'create_time' => null,
                'update_time' => null,
            ];
        }

        /** @var TableStatus */
        return $result;
    }
}

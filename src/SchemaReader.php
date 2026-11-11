<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;

use function preg_match;
use function sprintf;

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

    /** @return list<string> table keys: "posts", or "reporting.posts" when the statement qualifies the table */
    public static function tableNames(string $sql, QuoteRules $rules = new QuoteRules()): array
    {
        // quote-aware: a "--" inside a string literal is not a comment
        return TableReferences::keys(SqlSafetyClassifier::stripComments($sql, $rules));
    }

    /**
     * @param string $tableName a table key: "posts" for a table of the connection's database, or
     *                          "reporting.posts" for one of another schema
     *
     * @return SchemaInfo
     *
     * @throws RuntimeException
     */
    public function forTable(string $tableName): array
    {
        if (! preg_match('/^(?:[a-zA-Z0-9_]+\.)?[a-zA-Z0-9_]+$/', $tableName)) {
            throw new RuntimeException('Invalid table name');
        }

        [$schemaName, $table] = TableReferences::split($tableName);
        $where = sprintf(
            'table_schema = %s AND table_name = %s',
            $schemaName === null ? 'DATABASE()' : $this->pdo->quote($schemaName),
            $this->pdo->quote($table),
        );

        return [
            'columns' => $this->columns($where),
            'indexes' => $this->indexes($where),
            'status' => $this->status($where),
        ];
    }

    /**
     * @return list<SchemaColumn>
     *
     * @throws RuntimeException
     */
    private function columns(string $where): array
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
            WHERE {$where}
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
    private function indexes(string $where): array
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
            WHERE {$where}
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
    private function status(string $where): array
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
        WHERE {$where}
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

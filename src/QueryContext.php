<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_values;
use function explode;
use function ksort;
use function preg_match;
use function preg_match_all;
use function strtolower;
use function trim;

use const PREG_SET_ORDER;

/**
 * @psalm-immutable
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type ExplainTableAccess from Types
 * @psalm-import-type ShowWarnings from Types
 * @psalm-import-type ShowWarning from Types
 * @psalm-import-type SchemaInfo from Types
 * @psalm-import-type OptimizerTraceExcerpt from Types
 * @psalm-import-type OptimizerTraceTable from Types
 */
final class QueryContext
{
    private const TABLE_REFERENCE_LIST = '/\b(?:FROM|JOIN|UPDATE)\s+(`?\w+`?(?:\s+(?:AS\s+)?(?!(?:WHERE|ON|SET|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|USING|GROUP|ORDER|LIMIT|HAVING|UNION|WINDOW|FOR|LOCK|INTO|PARTITION|USE|IGNORE|FORCE)\b)`?\w+`?)?(?:\s*,\s*`?\w+`?(?:\s+(?:AS\s+)?(?!(?:WHERE|ON|SET|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|USING|GROUP|ORDER|LIMIT|HAVING|UNION|WINDOW|FOR|LOCK|INTO|PARTITION|USE|IGNORE|FORCE)\b)`?\w+`?)?)*)/i';
    private const TABLE_REFERENCE_ITEM = '/^`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?$/i';

    /**
     * @param ExplainResult              $explain
     * @param ShowWarnings               $warnings
     * @param array<string, SchemaInfo>  $schema         keyed by table name
     * @param string                     $sql            the statement as sent to MySQL, parameters interpolated
     * @param string|null                $explainAnalyze null unless the statement is a read-only SELECT
     * @param OptimizerTraceExcerpt|null $optimizerTrace null when the trace was not captured, unreadable or truncated
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $explain,
        public readonly string|null $explainAnalyze,
        public readonly array $warnings,
        public readonly array $schema,
        public readonly array|null $optimizerTrace = null,
    ) {
    }

    /**
     * @param int|null $select the query block (select_id) the table belongs to; null takes the first block naming it
     *
     * @return OptimizerTraceTable|null what the optimizer recorded for this table of the final plan
     */
    public function optimizerTraceFor(string $tableName, int|null $select = null): array|null
    {
        foreach ($this->optimizerTrace['tables'] ?? [] as $table) {
            if ($table['table'] === $tableName && ($select === null || $table['select'] === $select)) {
                return $table;
            }
        }

        return null;
    }

    /** @return list<ExplainTable> */
    public function tables(): array
    {
        return (new ExplainWalker())->tables($this->explain['query_block']);
    }

    /** @return list<ExplainTableAccess> */
    public function tableAccesses(): array
    {
        return (new ExplainWalker())->tableAccesses($this->explain['query_block']);
    }

    /** @return list<list<ExplainTable>> tables of each nested_loop in member order */
    public function nestedLoops(): array
    {
        return (new ExplainWalker())->nestedLoops($this->explain['query_block']);
    }

    /** @return list<ShowWarning> */
    public function warningsWithCode(int $code): array
    {
        $matched = [];
        foreach ($this->warnings as $warning) {
            if ($warning['Code'] === $code) {
                $matched[] = $warning;
            }
        }

        return $matched;
    }

    /** @return array<string, list<string>> index name => column names in seq_in_index order; [] when the table is not in schema */
    public function indexColumns(string $aliasOrTable): array
    {
        $schema = $this->schemaFor($aliasOrTable);
        if ($schema === null) {
            return [];
        }

        $byPosition = [];
        foreach ($schema['indexes'] as $index) {
            $byPosition[$index['INDEX_NAME']][$index['SEQ_IN_INDEX']] = $index['COLUMN_NAME'];
        }

        $columns = [];
        foreach ($byPosition as $name => $positions) {
            ksort($positions);
            $columns[$name] = array_values($positions);
        }

        return $columns;
    }

    /** @return string the statement with line (--) and block comments removed */
    public function sqlWithoutComments(): string
    {
        return SqlSafetyClassifier::stripComments($this->sql);
    }

    /** @return array<string, string> alias => table name; a table without alias maps to itself. Derived tables are not included */
    public function aliases(): array
    {
        $sql = $this->sqlWithoutComments();
        preg_match_all(self::TABLE_REFERENCE_LIST, $sql, $matches, PREG_SET_ORDER);

        $aliases = [];
        foreach ($matches as $match) {
            foreach (explode(',', $match[1]) as $reference) {
                if (preg_match(self::TABLE_REFERENCE_ITEM, trim($reference), $item) !== 1) {
                    continue;
                }

                $table = $item[1];
                $alias = ($item[2] ?? '') === '' ? $table : $item[2];
                $aliases[$alias] = $table;
            }
        }

        return $aliases;
    }

    /** @return SchemaInfo|null */
    public function schemaFor(string $aliasOrTable): array|null
    {
        $table = $this->aliases()[$aliasOrTable] ?? $aliasOrTable;

        return $this->schema[$table] ?? null;
    }

    /** @return string|null information_schema data_type in lowercase; null when the table or column is unknown */
    public function columnType(string $aliasOrTable, string $column): string|null
    {
        foreach ($this->schemaFor($aliasOrTable)['columns'] ?? [] as $schemaColumn) {
            if ($schemaColumn['COLUMN_NAME'] === $column) {
                return strtolower($schemaColumn['DATA_TYPE']);
            }
        }

        return null;
    }

    /** @return list<string> */
    public function primaryKeyColumns(string $aliasOrTable): array
    {
        $columns = [];
        foreach ($this->schemaFor($aliasOrTable)['columns'] ?? [] as $schemaColumn) {
            if ($schemaColumn['COLUMN_KEY'] === 'PRI') {
                $columns[] = $schemaColumn['COLUMN_NAME'];
            }
        }

        return $columns;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sql' => $this->sql,
            'explain' => $this->explain,
            'explain_analyze' => $this->explainAnalyze,
            'warnings' => $this->warnings,
            'schema' => $this->schema,
            'optimizer_trace' => $this->optimizerTrace,
        ];
    }

    /** @param array<string, mixed> $data optimizer_trace may be absent in fixtures recorded before it was captured */
    public static function fromArray(array $data): self
    {
        /** @var string $sql */
        $sql = $data['sql'];
        /** @var ExplainResult $explain */
        $explain = $data['explain'];
        /** @var string|null $explainAnalyze */
        $explainAnalyze = $data['explain_analyze'];
        /** @var ShowWarnings $warnings */
        $warnings = $data['warnings'];
        /** @var array<string, SchemaInfo> $schema */
        $schema = $data['schema'];
        /** @var OptimizerTraceExcerpt|null $optimizerTrace */
        $optimizerTrace = $data['optimizer_trace'] ?? null;

        return new self($sql, $explain, $explainAnalyze, $warnings, $schema, $optimizerTrace);
    }
}

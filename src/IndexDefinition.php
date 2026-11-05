<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\InvalidIndexDefinition;

use function array_map;
use function explode;
use function implode;
use function preg_match;
use function sprintf;
use function strlen;
use function strtoupper;
use function substr;
use function trim;

/**
 * A secondary index to try, parsed from `CREATE INDEX name ON table (col, col(10) DESC)` (the form of a
 * suggestion's ddl) or the short `table(col, col)`. Only plain identifiers are accepted, so the DDL built
 * from it cannot carry anything but an index definition.
 */
final class IndexDefinition
{
    private const IDENTIFIER = '`?([A-Za-z0-9_$]+)`?';
    private const MAX_NAME_LENGTH = 64;

    /** @param list<array{name: string, length: int|null, order: 'ASC'|'DESC'|null}> $columns */
    private function __construct(
        public readonly string $table,
        public readonly string $name,
        public readonly array $columns,
    ) {
    }

    /** @throws InvalidIndexDefinition */
    public static function parse(string $definition): self
    {
        $definition = trim($definition, " \t\n\r;");
        if (preg_match('/^CREATE\s+INDEX\s+' . self::IDENTIFIER . '\s+ON\s+' . self::IDENTIFIER . '\s*\((.+)\)$/is', $definition, $matches)) {
            return new self($matches[2], self::checkedName($matches[1], $definition), self::columns($matches[3], $definition));
        }

        if (preg_match('/^' . self::IDENTIFIER . '\s*\((.+)\)$/s', $definition, $matches)) {
            $columns = self::columns($matches[2], $definition);
            $name = 'sqlq_trial_' . $matches[1] . '_' . implode('_', array_map(static fn (array $column): string => $column['name'], $columns));

            return new self($matches[1], substr($name, 0, self::MAX_NAME_LENGTH), $columns);
        }

        throw new InvalidIndexDefinition($definition);
    }

    /** @throws InvalidIndexDefinition */
    private static function checkedName(string $name, string $definition): string
    {
        if (strlen($name) > self::MAX_NAME_LENGTH) {
            throw new InvalidIndexDefinition(sprintf('%s (the index name is longer than %d characters)', $definition, self::MAX_NAME_LENGTH));
        }

        return $name;
    }

    /**
     * @return list<array{name: string, length: int|null, order: 'ASC'|'DESC'|null}>
     *
     * @throws InvalidIndexDefinition
     */
    private static function columns(string $list, string $definition): array
    {
        $columns = [];
        foreach (explode(',', $list) as $part) {
            if (! preg_match('/^\s*' . self::IDENTIFIER . '\s*(?:\(\s*(\d+)\s*\))?\s*(ASC|DESC)?\s*$/i', $part, $matches)) {
                throw new InvalidIndexDefinition($definition);
            }

            $order = isset($matches[3]) && $matches[3] !== '' ? strtoupper($matches[3]) : null;
            /** @var 'ASC'|'DESC'|null $order */
            $columns[] = [
                'name' => $matches[1],
                'length' => isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null,
                'order' => $order,
            ];
        }

        return $columns;
    }

    /** @return list<string> */
    public function columnNames(): array
    {
        return array_map(static fn (array $column): string => $column['name'], $this->columns);
    }

    /** `CREATE INDEX name ON table (columns)`, the statement that adds the index for good */
    public function createStatement(): string
    {
        return sprintf('CREATE INDEX `%s` ON `%s` (%s)', $this->name, $this->table, $this->columnList());
    }

    /**
     * Adds the index invisible and online: writes continue while it builds, and a build that cannot run
     * without locking the table fails instead. The ALTER still waits for a metadata lock behind open
     * transactions; IndexTrial bounds that wait with lock_wait_timeout.
     */
    public function addInvisibleStatement(): string
    {
        return sprintf('ALTER TABLE `%s` ADD INDEX `%s` (%s) INVISIBLE, ALGORITHM=INPLACE, LOCK=NONE', $this->table, $this->name, $this->columnList());
    }

    public function makeVisibleStatement(): string
    {
        return sprintf('ALTER TABLE `%s` ALTER INDEX `%s` VISIBLE', $this->table, $this->name);
    }

    public function dropStatement(): string
    {
        return sprintf('DROP INDEX `%s` ON `%s`', $this->name, $this->table);
    }

    private function columnList(): string
    {
        return implode(', ', array_map(
            static fn (array $column): string => '`' . $column['name'] . '`'
                . ($column['length'] === null ? '' : '(' . $column['length'] . ')')
                . ($column['order'] === null ? '' : ' ' . $column['order']),
            $this->columns,
        ));
    }
}

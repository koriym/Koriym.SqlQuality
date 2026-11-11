<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_values;
use function explode;
use function preg_match;
use function preg_match_all;
use function trim;

use const PREG_SET_ORDER;

/**
 * The tables a statement names after FROM, JOIN and UPDATE, with an optional schema qualifier
 * (`reporting.posts`) and alias. Derived tables and table functions are not included.
 *
 * A table is keyed by its qualified name, "reporting.posts", or by its bare name, "posts", when the
 * statement does not qualify it. EXPLAIN reports a table by its alias, or by its bare name when it has
 * none, so the alias of an unaliased qualified table is its bare name.
 */
final class TableReferences
{
    private const KEYWORDS = 'WHERE|ON|SET|JOIN|LEFT|RIGHT|INNER|OUTER|CROSS|NATURAL|STRAIGHT_JOIN|USING|GROUP|ORDER|LIMIT|HAVING|UNION|WINDOW|FOR|LOCK|INTO|PARTITION|USE|IGNORE|FORCE';
    private const TABLE = '(?:`?\w+`?\s*\.\s*)?`?\w+`?';
    private const ALIAS = '(?:\s+(?:AS\s+)?(?!(?:' . self::KEYWORDS . ')\b)`?\w+`?)?';
    private const REFERENCE = self::TABLE . self::ALIAS;
    private const LIST = '/\b(?:FROM|JOIN|UPDATE)\s+(' . self::REFERENCE . '(?:\s*,\s*' . self::REFERENCE . ')*)/i';
    private const ITEM = '/^(?:`?(\w+)`?\s*\.\s*)?`?(\w+)`?(?:\s+(?:AS\s+)?`?(\w+)`?)?$/i';

    /**
     * @param string $sqlWithoutComments the statement with comments already stripped, quote-aware
     *
     * @return array<string, string> alias (or bare table name) => table key
     *
     * @psalm-pure
     */
    public static function aliases(string $sqlWithoutComments): array
    {
        preg_match_all(self::LIST, $sqlWithoutComments, $matches, PREG_SET_ORDER);

        $aliases = [];
        foreach ($matches as $match) {
            foreach (explode(',', $match[1]) as $reference) {
                if (preg_match(self::ITEM, trim($reference), $item) !== 1) {
                    continue;
                }

                $schema = $item[1];
                $table = $item[2];
                $alias = ($item[3] ?? '') === '' ? $table : $item[3];
                $aliases[$alias] = $schema === '' ? $table : $schema . '.' . $table;
            }
        }

        return $aliases;
    }

    /**
     * @param string $sqlWithoutComments the statement with comments already stripped, quote-aware
     *
     * @return list<string> table keys, each once, in order of appearance
     *
     * @psalm-pure
     */
    public static function keys(string $sqlWithoutComments): array
    {
        $keys = [];
        foreach (self::aliases($sqlWithoutComments) as $key) {
            $keys[$key] = $key;
        }

        return array_values($keys);
    }

    /** @return array{0: string|null, 1: string} the schema (null when unqualified) and the table of a key */
    public static function split(string $key): array
    {
        $parts = explode('.', $key, 2);

        return isset($parts[1]) ? [$parts[0], $parts[1]] : [null, $parts[0]];
    }

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}

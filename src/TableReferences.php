<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_values;
use function explode;
use function preg_match;
use function preg_match_all;
use function preg_replace_callback;
use function str_repeat;
use function strlen;
use function substr;
use function trim;

use const PREG_OFFSET_CAPTURE;
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
        $aliases = [];
        foreach (self::references($sqlWithoutComments) as [$alias, $key, $explicit]) {
            // "FROM posts ... FROM other.posts": both are reported as "posts"; the unqualified table keeps the name
            if (! $explicit && $key !== $alias) {
                $aliases[$alias] ??= $key;

                continue;
            }

            $aliases[$alias] = $key;
        }

        return $aliases;
    }

    /**
     * Every table referenced, also when two references share an alias (a subquery reusing "p").
     *
     * @param string $sqlWithoutComments the statement with comments already stripped, quote-aware
     *
     * @return list<string> table keys, each once, in order of appearance
     *
     * @psalm-pure
     */
    public static function keys(string $sqlWithoutComments): array
    {
        $keys = [];
        foreach (self::references($sqlWithoutComments) as [, $key]) {
            $keys[$key] = $key;
        }

        return array_values($keys);
    }

    /**
     * @return list<array{0: string, 1: string, 2: bool}> [alias or bare table name, table key, alias given] per reference
     *
     * @psalm-pure
     */
    private static function references(string $sqlWithoutComments): array
    {
        // string literals blanked to the same length: a "(" or "FROM" inside one is not syntax
        $sql = self::blankLiterals($sqlWithoutComments);
        preg_match_all(self::LIST, $sql, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $references = [];
        foreach ($matches as $match) {
            if (self::isFunctionArgument($sql, $match[0][1])) {
                continue;
            }

            foreach (explode(',', $match[1][0]) as $reference) {
                if (preg_match(self::ITEM, trim($reference), $item) !== 1) {
                    continue;
                }

                $schema = $item[1];
                $table = $item[2];
                $explicit = ($item[3] ?? '') !== '';
                $references[] = [$explicit ? $item[3] : $table, $schema === '' ? $table : $schema . '.' . $table, $explicit];
            }
        }

        return $references;
    }

    /**
     * Whether the keyword at $offset is the FROM of EXTRACT(YEAR FROM t.c), TRIM(x FROM t.c) or
     * SUBSTRING(t.c FROM 2): "t.c" there is a column, not a schema-qualified table. A parenthesised join
     * ("JOIN (posts p JOIN comments c ...)") or a subquery is not a function call.
     *
     * @psalm-pure
     */
    private static function isFunctionArgument(string $sql, int $offset): bool
    {
        $depth = 0;
        for ($i = $offset - 1; $i >= 0; $i--) {
            if ($sql[$i] === ')') {
                $depth++;
            } elseif ($sql[$i] === '(') {
                if ($depth === 0) {
                    return preg_match('/\b(?:EXTRACT|TRIM|SUBSTRING|SUBSTR)\s*$/i', substr($sql, 0, $i)) === 1;
                }

                $depth--;
            }
        }

        return false;
    }

    /** @psalm-pure */
    private static function blankLiterals(string $sql): string
    {
        return (string) preg_replace_callback(
            "/'(?:[^'\\\\]++|\\\\.|'')*+'|\"(?:[^\"\\\\]++|\\\\.|\"\")*+\"/s",
            static fn (array $literal): string => str_repeat(' ', strlen($literal[0])),
            $sql,
        );
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

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\EmptyListParameter;
use Koriym\SqlQuality\Exception\InvalidParameterValue;
use Koriym\SqlQuality\Exception\UnboundParameter;
use PDO;

use function array_diff;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function implode;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function preg_match;
use function sort;
use function strlen;
use function strpos;
use function substr;
use function var_export;

/**
 * Replaces :name placeholders with quoted literals, so EXPLAIN sees the statement the application runs.
 *
 * The statement is scanned once: string literals, quoted identifiers and comments are copied as they
 * are, so a ":name" inside them is left alone, and an inserted value is never scanned again.
 */
final class ParameterBinder
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Placeholder names in the statement, outside comments, string literals and quoted identifiers.
     *
     * @return list<string>
     */
    public static function placeholders(string $sql, QuoteRules $rules = new QuoteRules()): array
    {
        $names = [];
        self::scan($sql, $rules, static function (string $name) use (&$names): string {
            $names[] = $name;

            return ':' . $name;
        });
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws UnboundParameter
     * @throws EmptyListParameter
     * @throws InvalidParameterValue
     */
    public function bind(string $sql, array $params, QuoteRules $rules = new QuoteRules()): string
    {
        $used = self::placeholders($sql, $rules);
        $missing = array_values(array_diff($used, array_map('strval', array_keys($params))));
        if ($missing !== []) {
            throw new UnboundParameter(implode(', ', $missing));
        }

        // only the parameters the statement uses: a shared params object can hold values (an empty
        // list) that would be rejected for a statement that never references them
        $literals = [];
        foreach ($used as $name) {
            $literals[$name] = $this->literal($name, $params[$name]);
        }

        return self::scan($sql, $rules, static function (string $name, bool $inVersionedComment) use ($literals): string {
            // a server older than the comment's version skips its body without regard to quotes, so a
            // quoted value there could end the comment early and put the rest of the value into the statement
            if ($inVersionedComment) {
                throw new InvalidParameterValue("{$name}: a placeholder inside a /*! versioned comment */ is not bound");
            }

            return array_key_exists($name, $literals) ? $literals[$name] : ':' . $name;
        });
    }

    /**
     * Calls $placeholder for every :name outside literals and comments, and puts its return value in its place.
     *
     * A character loop, not a regular expression: a long literal or comment exhausts the PCRE stack, and
     * preg_replace_callback() then returns null, which would silently empty the statement.
     *
     * @param callable(string, bool): string $placeholder receives the name and whether it sits inside a
     *                                                    versioned comment
     */
    private static function scan(string $sql, QuoteRules $rules, callable $placeholder): string
    {
        $length = strlen($sql);
        $out = '';
        $versioned = false;
        $offset = 0;
        while ($offset < $length) {
            $char = $sql[$offset];
            $next = $sql[$offset + 1] ?? '';

            if ($versioned && $char === '*' && $next === '/') {
                $versioned = false;
                $out .= '*/';
                $offset += 2;
                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $end = self::literalEnd($sql, $offset, $rules);
                $out .= substr($sql, $offset, $end - $offset);
                $offset = $end;
                continue;
            }

            // "-- " starts a comment when a space or control character follows, as MySQL lexes it
            if ($char === '#' || ($char === '-' && $next === '-' && ($offset + 2 >= $length || ord($sql[$offset + 2]) <= 32))) {
                $end = strpos($sql, "\n", $offset);
                $end = $end === false ? $length : $end;
                $out .= substr($sql, $offset, $end - $offset);
                $offset = $end;
                continue;
            }

            if ($char === '/' && $next === '*') {
                if (($sql[$offset + 2] ?? '') === '!' && ! $versioned) {
                    // a versioned comment /*!NNNNN ... */ is code to a server new enough, and skipped by an older one
                    $versioned = true;
                    $out .= '/*!';
                    $offset += 3;
                    continue;
                }

                $end = strpos($sql, '*/', $offset + 2);
                $end = $end === false ? $length : $end + 2;
                $out .= substr($sql, $offset, $end - $offset);
                $offset = $end;
                continue;
            }

            if ($char === ':' && preg_match('/\G[A-Za-z_]\w*/', $sql, $match, 0, $offset + 1) === 1 && ! self::continuesToken($sql, $offset)) {
                $out .= $placeholder($match[0], $versioned);
                $offset += 1 + strlen($match[0]);
                continue;
            }

            $out .= $char;
            $offset++;
        }

        return $out;
    }

    /** The offset just past the quoted literal or identifier starting at $start, or the end of the statement. */
    private static function literalEnd(string $sql, int $start, QuoteRules $rules): int
    {
        $quote = $sql[$start];
        $escapes = $rules->backslashEscapesIn($quote);
        $length = strlen($sql);
        $offset = $start + 1;
        while ($offset < $length) {
            $char = $sql[$offset];
            if ($escapes && $char === '\\') {
                $offset += 2;
                continue;
            }

            if ($char === $quote) {
                if (($sql[$offset + 1] ?? '') === $quote) {
                    $offset += 2;
                    continue;
                }

                return $offset + 1;
            }

            $offset++;
        }

        return $length;
    }

    /** Whether the ':' at $offset follows a word character or another ':', so it is not a placeholder (a::b, x:y). */
    private static function continuesToken(string $sql, int $offset): bool
    {
        if ($offset === 0) {
            return false;
        }

        $previous = $sql[$offset - 1];

        return $previous === ':' || preg_match('/\w/', $previous) === 1;
    }

    /**
     * @throws EmptyListParameter
     * @throws InvalidParameterValue
     */
    private function literal(string $name, mixed $value): string
    {
        if (! is_array($value)) {
            return $this->scalar($name, $value);
        }

        if ($value === []) {
            throw new EmptyListParameter($name);
        }

        if (! array_is_list($value)) {
            throw new InvalidParameterValue("{$name}: an object is not a parameter value; give a scalar or a list of scalars");
        }

        return implode(',', array_map(fn (mixed $item): string => $this->scalar($name, $item), $value));
    }

    /** @throws InvalidParameterValue */
    private function scalar(string $name, mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            // INF and NAN have no SQL literal; var_export() would write the bare word INF, read as a column name
            is_float($value) && ! is_finite($value) => throw new InvalidParameterValue("{$name}: a float parameter must be finite, not " . var_export($value, true)),
            is_float($value) => var_export($value, true),
            is_string($value) => $this->pdo->quote($value),
            default => throw new InvalidParameterValue("{$name}: a list item must be a scalar, not a nested list or object"),
        };
    }
}

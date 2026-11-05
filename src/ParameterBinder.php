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
use function is_float;
use function is_int;
use function is_string;
use function preg_replace_callback;
use function sort;
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
        $missing = array_values(array_diff(self::placeholders($sql, $rules), array_map('strval', array_keys($params))));
        if ($missing !== []) {
            throw new UnboundParameter(implode(', ', $missing));
        }

        $literals = [];
        foreach ($params as $name => $value) {
            $literals[(string) $name] = $this->literal((string) $name, $value);
        }

        return self::scan($sql, $rules, static fn (string $name): string => array_key_exists($name, $literals) ? $literals[$name] : ':' . $name);
    }

    /**
     * Calls $placeholder for every :name outside literals and comments, and puts its return value in its place.
     *
     * @param callable(string): string $placeholder
     */
    private static function scan(string $sql, QuoteRules $rules, callable $placeholder): string
    {
        $alternatives = [];
        foreach (['\'', '"', '`'] as $quote) {
            $body = $rules->backslashEscapesIn($quote) ? "[^{$quote}\\\\]|\\\\.|{$quote}{$quote}" : "[^{$quote}]|{$quote}{$quote}";
            $alternatives[] = "{$quote}(?:{$body})*(?:{$quote}|\\z)";
        }

        $alternatives[] = '--(?=[ \t\r\n]|\z)[^\n]*';
        $alternatives[] = '#[^\n]*';
        // a versioned comment /*!NNNNN ... */ is code to MySQL, so it is scanned like code
        $alternatives[] = '\/\*(?!!).*?(?:\*\/|\z)';
        $pattern = '/' . implode('|', $alternatives) . '|(?<![:\w]):([A-Za-z_]\w*)/s';

        return (string) preg_replace_callback(
            $pattern,
            /** @param array<array-key, string> $match */
            static fn (array $match): string => isset($match[1]) && $match[1] !== '' ? $placeholder($match[1]) : $match[0],
            $sql,
        );
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
            is_int($value), is_float($value) => var_export($value, true),
            is_string($value) => $this->pdo->quote($value),
            default => throw new InvalidParameterValue("{$name}: a list item must be a scalar, not a nested list or object"),
        };
    }
}

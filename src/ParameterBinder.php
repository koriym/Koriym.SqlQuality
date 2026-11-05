<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\EmptyListParameter;
use Koriym\SqlQuality\Exception\UnboundParameter;
use PDO;

use function array_diff;
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
use function preg_match_all;
use function preg_quote;
use function preg_replace_callback;
use function sort;
use function var_export;

/**
 * Replaces :name placeholders with quoted literals, so EXPLAIN sees the statement the application runs.
 */
final class ParameterBinder
{
    private const PLACEHOLDER = '/(?<![:\w]):([A-Za-z_]\w*)/';

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
        $structure = SqlSafetyClassifier::stripLiterals(SqlSafetyClassifier::stripComments($sql, $rules), $rules);
        preg_match_all(self::PLACEHOLDER, $structure, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws UnboundParameter
     * @throws EmptyListParameter
     */
    public function bind(string $sql, array $params, QuoteRules $rules = new QuoteRules()): string
    {
        $missing = array_values(array_diff(self::placeholders($sql, $rules), array_map('strval', array_keys($params))));
        if ($missing !== []) {
            throw new UnboundParameter(implode(', ', $missing));
        }

        foreach ($params as $name => $value) {
            $literal = $this->literal((string) $name, $value);
            $sql = (string) preg_replace_callback(
                '/(?<![:\w]):' . preg_quote((string) $name, '/') . '\b/',
                static fn (): string => $literal,
                $sql,
            );
        }

        return $sql;
    }

    /** @throws EmptyListParameter */
    private function literal(string $name, mixed $value): string
    {
        if (is_array($value)) {
            if ($value === []) {
                throw new EmptyListParameter($name);
            }

            return implode(',', array_map(fn (mixed $item): string => $this->scalar($item), $value));
        }

        return $this->scalar($value);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => var_export($value, true),
            is_string($value) => $this->pdo->quote($value),
            default => $this->pdo->quote(var_export($value, true)),
        };
    }
}

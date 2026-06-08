<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function ord;
use function preg_match;
use function preg_replace;
use function strlen;
use function strtolower;
use function substr;
use function trim;

/**
 * Classifies SQL statements before WD execution.
 *
 * EXPLAIN FORMAT=JSON is safe for SELECT and DML, but EXPLAIN ANALYZE and
 * timing loops execute the statement and are restricted to read-only SELECT.
 *
 * @psalm-immutable
 * @psalm-import-type SqlClassification from Types
 */
final class SqlSafetyClassifier
{
    /** @return SqlClassification */
    public function classify(string $sql): array
    {
        if (self::containsStackedStatement($sql)) {
            return ['kind' => 'unsafe', 'is_explainable' => false, 'is_read_only_select' => false, 'reason' => 'stacked SQL statements are not allowed in WD mode'];
        }

        $normalized = $this->normalize($sql);
        if ($normalized === '') {
            return ['kind' => 'empty', 'is_explainable' => false, 'is_read_only_select' => false, 'reason' => 'empty SQL'];
        }

        if (preg_match('/^select\b/i', $normalized)) {
            if ($this->containsUnsafeReadSideEffect($normalized)) {
                return ['kind' => 'select', 'is_explainable' => true, 'is_read_only_select' => false, 'reason' => 'execution skipped: unsafe SELECT construct'];
            }

            return ['kind' => 'select', 'is_explainable' => true, 'is_read_only_select' => true, 'reason' => 'read-only SELECT'];
        }

        if (preg_match('/^with\b/i', $normalized)) {
            if (preg_match('/\b(insert|update|delete|replace|merge)\b/i', $normalized)) {
                return ['kind' => 'write', 'is_explainable' => true, 'is_read_only_select' => false, 'reason' => 'execution skipped: WITH statement contains write operation'];
            }

            if (preg_match('/\bselect\b/i', $normalized)) {
                if ($this->containsUnsafeReadSideEffect($normalized)) {
                    return ['kind' => 'select', 'is_explainable' => true, 'is_read_only_select' => false, 'reason' => 'execution skipped: unsafe WITH SELECT construct'];
                }

                return ['kind' => 'select', 'is_explainable' => true, 'is_read_only_select' => true, 'reason' => 'read-only WITH SELECT'];
            }
        }

        if (preg_match('/^(insert|update|delete|replace)\b/i', $normalized)) {
            return ['kind' => 'write', 'is_explainable' => true, 'is_read_only_select' => false, 'reason' => 'execution skipped: write statement'];
        }

        if (preg_match('/^(create|alter|drop|truncate|rename)\b/i', $normalized)) {
            return ['kind' => 'ddl', 'is_explainable' => false, 'is_read_only_select' => false, 'reason' => 'DDL statement is not explainable in WD mode'];
        }

        if (preg_match('/^(call|set|lock|unlock|analyze|optimize|repair)\b/i', $normalized)) {
            return ['kind' => 'unsafe', 'is_explainable' => false, 'is_read_only_select' => false, 'reason' => 'unsafe statement is not explainable in WD mode'];
        }

        return ['kind' => 'other', 'is_explainable' => false, 'is_read_only_select' => false, 'reason' => 'statement is not explainable in WD mode'];
    }

    public function isExplainable(string $sql): bool
    {
        return $this->classify($sql)['is_explainable'];
    }

    public function isReadOnlySelect(string $sql): bool
    {
        return $this->classify($sql)['is_read_only_select'];
    }

    /** @psalm-pure */
    public function normalize(string $sql): string
    {
        $withoutComments = self::stripComments($sql);
        $collapsed = preg_replace('/\s+/', ' ', $withoutComments) ?? $withoutComments;

        return trim($collapsed);
    }

    /** @psalm-pure */
    private static function containsStackedStatement(string $normalizedSql): bool
    {
        $quote = null;
        $isEscaped = false;
        $length = strlen($normalizedSql);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $normalizedSql[$offset];

            if ($isEscaped) {
                $isEscaped = false;

                continue;
            }

            if ($quote !== null) {
                if ($char === '\\') {
                    $isEscaped = true;

                    continue;
                }

                if ($char === $quote) {
                    if ($offset + 1 < $length && $normalizedSql[$offset + 1] === $quote) {
                        $offset++;

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === '/' && $offset + 1 < $length && $normalizedSql[$offset + 1] === '*') {
                $offset += 2;
                while ($offset + 1 < $length && ! ($normalizedSql[$offset] === '*' && $normalizedSql[$offset + 1] === '/')) {
                    $offset++;
                }

                $offset++;

                continue;
            }

            if (self::isMysqlDashCommentStart($normalizedSql, $offset, $length)) {
                $offset += 2;
                while ($offset < $length && $normalizedSql[$offset] !== "\n" && $normalizedSql[$offset] !== "\r") {
                    $offset++;
                }

                continue;
            }

            if ($char === '#') {
                while ($offset < $length && $normalizedSql[$offset] !== "\n" && $normalizedSql[$offset] !== "\r") {
                    $offset++;
                }

                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;

                continue;
            }

            if ($char === ';' && self::hasExecutableTail(substr($normalizedSql, $offset + 1))) {
                return true;
            }
        }

        return false;
    }

    /** @psalm-pure */
    private static function hasExecutableTail(string $sql): bool
    {
        return trim(self::stripComments($sql)) !== '';
    }

    /** @psalm-pure */
    private static function stripComments(string $sql): string
    {
        $result = '';
        $quote = null;
        $isEscaped = false;
        $length = strlen($sql);
        for ($offset = 0; $offset < $length; $offset++) {
            $char = $sql[$offset];

            if ($isEscaped) {
                $result .= $char;
                $isEscaped = false;

                continue;
            }

            if ($quote !== null) {
                $result .= $char;
                if ($char === '\\') {
                    $isEscaped = true;

                    continue;
                }

                if ($char === $quote) {
                    if ($offset + 1 < $length && $sql[$offset + 1] === $quote) {
                        $offset++;
                        $result .= $sql[$offset];

                        continue;
                    }

                    $quote = null;
                }

                continue;
            }

            if ($char === '/' && $offset + 1 < $length && $sql[$offset + 1] === '*') {
                $result .= ' ';
                $offset += 2;
                while ($offset + 1 < $length && ! ($sql[$offset] === '*' && $sql[$offset + 1] === '/')) {
                    $offset++;
                }

                $offset++;

                continue;
            }

            if (self::isMysqlDashCommentStart($sql, $offset, $length)) {
                $result .= ' ';
                $offset += 2;
                while ($offset < $length && $sql[$offset] !== "\n" && $sql[$offset] !== "\r") {
                    $offset++;
                }

                continue;
            }

            if ($char === '#') {
                $result .= ' ';
                while ($offset < $length && $sql[$offset] !== "\n" && $sql[$offset] !== "\r") {
                    $offset++;
                }

                continue;
            }

            if ($char === '\'' || $char === '"' || $char === '`') {
                $quote = $char;
            }

            $result .= $char;
        }

        return $result;
    }

    /** @psalm-pure */
    private static function isMysqlDashCommentStart(string $sql, int $offset, int $length): bool
    {
        return $offset + 2 < $length
            && $sql[$offset] === '-'
            && $sql[$offset + 1] === '-'
            && ord($sql[$offset + 2]) <= 32;
    }

    /** @psalm-pure */
    private function containsUnsafeReadSideEffect(string $normalizedSql): bool
    {
        $lower = strtolower($normalizedSql);

        return preg_match('/\bfor\s+update\b/i', $lower) === 1
            || preg_match('/\bfor\s+share\b/i', $lower) === 1
            || preg_match('/\block\s+in\s+share\s+mode\b/i', $lower) === 1
            || preg_match('/\binto\s+(?:out|dump)file\b/i', $lower) === 1
            || preg_match('/\bget_lock\s*\(/i', $lower) === 1
            || preg_match('/\bsleep\s*\(/i', $lower) === 1
            || preg_match('/\bcall\s+\w+/i', $lower) === 1;
    }
}

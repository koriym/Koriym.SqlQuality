<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function preg_match;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * Classifies SQL statements before WD execution.
 *
 * EXPLAIN FORMAT=JSON is safe for SELECT and DML, but EXPLAIN ANALYZE and
 * timing loops execute the statement and are restricted to read-only SELECT.
 *
 * @psalm-immutable
 */
final class SqlSafetyClassifier
{
    /** @return array{kind: string, is_explainable: bool, is_read_only_select: bool, reason: string} */
    public function classify(string $sql): array
    {
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
        $withoutBlockComments = preg_replace('/\/\*.*?\*\//s', ' ', $sql) ?? $sql;
        $withoutLineComments = preg_replace('/--.*$/m', ' ', $withoutBlockComments) ?? $withoutBlockComments;
        $withoutHashComments = preg_replace('/#.*$/m', ' ', $withoutLineComments) ?? $withoutLineComments;
        $collapsed = preg_replace('/\s+/', ' ', $withoutHashComments) ?? $withoutHashComments;

        return trim($collapsed);
    }

    /** @psalm-pure */
    private function containsUnsafeReadSideEffect(string $normalizedSql): bool
    {
        $lower = strtolower($normalizedSql);

        return preg_match('/\bfor\s+update\b/i', $lower) === 1
            || preg_match('/\block\s+in\s+share\s+mode\b/i', $lower) === 1
            || preg_match('/\binto\s+(?:out|dump)file\b/i', $lower) === 1
            || preg_match('/\bget_lock\s*\(/i', $lower) === 1
            || preg_match('/\bsleep\s*\(/i', $lower) === 1
            || preg_match('/\bcall\s+\w+/i', $lower) === 1;
    }
}

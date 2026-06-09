<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function ctype_alpha;
use function in_array;
use function preg_match;
use function sprintf;
use function strlen;
use function strtoupper;
use function trim;

/**
 * Classifies a SQL statement so the analyzer never executes anything unsafe.
 *
 * The classifier works on a normalized copy of the SQL (comments and string/identifier
 * literals removed). The normalized form is used for classification only; the original
 * SQL is always what gets sent to the database unchanged.
 *
 * @psalm-immutable
 * @psalm-import-type SqlCategory from SqlClassification
 */
final class SqlClassifier
{
    private const DML_KEYWORDS = ['INSERT', 'UPDATE', 'DELETE', 'REPLACE'];
    private const DDL_KEYWORDS = ['CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME'];
    private const SELECT_KEYWORDS = ['SELECT', 'TABLE', 'VALUES'];
    private const CTE_MAIN_KEYWORDS = ['SELECT', 'UPDATE', 'DELETE', 'INSERT', 'REPLACE'];

    /** Constructs that make an otherwise read-only SELECT unsafe to execute. */
    private const UNSAFE_SELECT_PATTERNS = [
        'FOR UPDATE' => '/\bFOR\s+UPDATE\b/',
        'FOR SHARE' => '/\bFOR\s+SHARE\b/',
        'LOCK IN SHARE MODE' => '/\bLOCK\s+IN\s+SHARE\s+MODE\b/',
        'INTO OUTFILE' => '/\bINTO\s+OUTFILE\b/',
        'INTO DUMPFILE' => '/\bINTO\s+DUMPFILE\b/',
        'SLEEP()' => '/\bSLEEP\s*\(/',
        'GET_LOCK()' => '/\bGET_LOCK\s*\(/',
        'RELEASE_LOCK()' => '/\bRELEASE_LOCK\s*\(/',
        'BENCHMARK()' => '/\bBENCHMARK\s*\(/',
    ];

    public function classify(string $sql): SqlClassification
    {
        $normalized = $this->normalize($sql);
        if ($normalized === '') {
            return new SqlClassification('other', false, false, 'Empty statement; not analyzed');
        }

        $keyword = $this->mainStatementKeyword($normalized);

        if (in_array($keyword, self::DML_KEYWORDS, true)) {
            return new SqlClassification(
                'dml',
                false,
                true,
                sprintf('%s statement: read-only EXPLAIN executed, query not run (no EXPLAIN ANALYZE/timing)', $keyword),
            );
        }

        if (in_array($keyword, self::DDL_KEYWORDS, true)) {
            return new SqlClassification(
                'ddl',
                false,
                false,
                sprintf('%s statement: not analyzed (DDL is not supported by EXPLAIN)', $keyword),
            );
        }

        if (in_array($keyword, self::SELECT_KEYWORDS, true)) {
            $unsafe = $this->findUnsafeConstruct($normalized);
            if ($unsafe !== null) {
                return new SqlClassification(
                    'unsafe_select',
                    false,
                    true,
                    sprintf('Unsafe SELECT (%s): read-only EXPLAIN executed, query not run', $unsafe),
                );
            }

            return new SqlClassification('read_only_select', true, true, null);
        }

        return new SqlClassification(
            'other',
            false,
            false,
            sprintf('%s statement: not analyzed', $keyword === '' ? 'Unknown' : $keyword),
        );
    }

    /** Returns the upper-cased leading keyword, resolving a leading WITH (CTE) to the main statement. */
    private function mainStatementKeyword(string $normalized): string
    {
        if (preg_match('/^[^A-Za-z]*([A-Za-z]+)/', $normalized, $matches) !== 1) {
            return '';
        }

        $first = strtoupper($matches[1]);
        if ($first !== 'WITH') {
            return $first;
        }

        return $this->resolveCteMainKeyword(strtoupper($normalized));
    }

    /**
     * Finds the main statement keyword that follows the CTE definitions of a WITH clause.
     * CTE bodies live inside parentheses, so the first top-level (depth 0) statement keyword wins.
     *
     * @psalm-pure
     */
    private function resolveCteMainKeyword(string $upper): string
    {
        $length = strlen($upper);
        $depth = 0;
        $token = '';
        for ($i = 0; $i < $length; $i++) {
            $char = $upper[$i];
            if ($char === '(') {
                $depth++;
                $token = '';
                continue;
            }

            if ($char === ')') {
                if ($depth > 0) {
                    $depth--;
                }

                $token = '';
                continue;
            }

            if (ctype_alpha($char)) {
                $token .= $char;
                continue;
            }

            if ($depth === 0 && in_array($token, self::CTE_MAIN_KEYWORDS, true)) {
                return $token;
            }

            $token = '';
        }

        if ($depth === 0 && in_array($token, self::CTE_MAIN_KEYWORDS, true)) {
            return $token;
        }

        return 'SELECT';
    }

    /** @psalm-pure */
    private function findUnsafeConstruct(string $normalized): string|null
    {
        $upper = strtoupper($normalized);
        foreach (self::UNSAFE_SELECT_PATTERNS as $label => $pattern) {
            if (preg_match($pattern, $upper) === 1) {
                return $label;
            }
        }

        return null;
    }

    /** Removes comments and string/identifier literals so classification never trips on their contents. */
    private function normalize(string $sql): string
    {
        $length = strlen($sql);
        $out = '';
        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($char === '-' && $next === '-') {
                $i = $this->skipToEndOfLine($sql, $i + 2, $length);
                $out .= ' ';
                continue;
            }

            if ($char === '#') {
                $i = $this->skipToEndOfLine($sql, $i + 1, $length);
                $out .= ' ';
                continue;
            }

            if ($char === '/' && $next === '*') {
                $i = $this->skipBlockComment($sql, $i + 2, $length);
                $out .= ' ';
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $i = $this->skipQuoted($sql, $i + 1, $char, $length);
                $out .= ' ';
                continue;
            }

            $out .= $char;
            $i++;
        }

        return trim($out);
    }

    /** @psalm-pure */
    private function skipToEndOfLine(string $sql, int $i, int $length): int
    {
        while ($i < $length && $sql[$i] !== "\n") {
            $i++;
        }

        return $i;
    }

    /** @psalm-pure */
    private function skipBlockComment(string $sql, int $i, int $length): int
    {
        while ($i < $length && ! ($sql[$i] === '*' && $i + 1 < $length && $sql[$i + 1] === '/')) {
            $i++;
        }

        return $i + 2;
    }

    /** @psalm-pure */
    private function skipQuoted(string $sql, int $i, string $quote, int $length): int
    {
        while ($i < $length) {
            $char = $sql[$i];
            if ($char === '\\' && $quote !== '`') {
                $i += 2;
                continue;
            }

            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $i += 2;
                    continue;
                }

                return $i + 1;
            }

            $i++;
        }

        return $i;
    }
}

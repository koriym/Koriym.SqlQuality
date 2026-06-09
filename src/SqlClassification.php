<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

/**
 * Result of classifying a SQL statement for safe analysis.
 *
 * - $executable  : whether the query itself may be run (EXPLAIN ANALYZE + timing loop).
 *                  Only true for read-only SELECT / WITH ... SELECT without unsafe constructs.
 * - $explainable : whether `EXPLAIN FORMAT=JSON` may be executed. This is read-only and safe
 *                  for SELECT and DML (INSERT/UPDATE/DELETE/REPLACE) alike, so DML is still
 *                  statically analyzable without ever running the statement.
 * - $skippedReason : human readable reason when the statement is not executed (null when executable).
 *
 * @psalm-immutable
 * @psalm-type SqlCategory = 'read_only_select'|'dml'|'unsafe_select'|'ddl'|'other'
 */
final class SqlClassification
{
    /** @param SqlCategory $category */
    public function __construct(
        public readonly string $category,
        public readonly bool $executable,
        public readonly bool $explainable,
        public readonly string|null $skippedReason,
    ) {
    }
}

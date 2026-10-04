<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The statement is not a SELECT, WITH or DML statement EXPLAIN FORMAT=JSON accepts.
 *
 * Classification happens before anything is sent, so DDL, CALL, SET and
 * stacked statements never reach the server behind an EXPLAIN. The
 * message is SqlSafetyClassifier::classify()'s reason, matching the
 * skipped_reason surfaced for a statement that is explainable but not executed.
 */
final class NotExplainable extends RuntimeException
{
}

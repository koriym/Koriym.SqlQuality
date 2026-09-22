<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * Execution timing is attempted only for a read-only SELECT.
 *
 * Timing runs the statement itself several times, so a statement that
 * writes, locks rows or calls a routine is never sent. The message is
 * the SQL.
 */
final class NotReadOnlySelect extends RuntimeException
{
}

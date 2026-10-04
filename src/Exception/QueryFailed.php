<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * PDO returned false instead of a statement for a timed execution.
 *
 * A connection whose error mode is not ERRMODE_EXCEPTION reports failure
 * this way, and timing cannot continue without a result set. The message
 * is the interpolated SQL.
 */
final class QueryFailed extends RuntimeException
{
}

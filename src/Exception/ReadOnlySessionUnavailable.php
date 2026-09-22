<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The connection cannot report its session read-only flag.
 *
 * Timing loops and EXPLAIN ANALYZE run the statement itself, so they are
 * attempted only inside a session that is known to be read-only. A driver
 * that does not answer for the flag cannot give that guarantee, and the
 * statement is not sent. The message is the PDO driver name.
 */
final class ReadOnlySessionUnavailable extends RuntimeException
{
}

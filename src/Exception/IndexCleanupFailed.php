<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The cleanup after an index trial failed: the trial index could not be checked for or removed (the
 * message then carries the statement to run by hand), or the session's optimizer_switch or
 * lock_wait_timeout could not be restored.
 */
final class IndexCleanupFailed extends RuntimeException
{
}

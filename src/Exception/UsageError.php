<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The command line cannot run as given: a missing or malformed option, a missing file, or a failed connection.
 */
final class UsageError extends RuntimeException
{
}

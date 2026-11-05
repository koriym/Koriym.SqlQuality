<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The trial index could not be dropped after the measurement. The message carries the DDL to run by hand.
 */
final class IndexCleanupFailed extends RuntimeException
{
}

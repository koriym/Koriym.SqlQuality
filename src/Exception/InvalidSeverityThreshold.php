<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The values --fail-on accepts are critical, warning and info.
 *
 * Anything else would silently never match an issue severity, so the run
 * stops instead of reporting success. The message is the rejected value.
 */
final class InvalidSeverityThreshold extends RuntimeException
{
}

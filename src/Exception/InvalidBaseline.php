<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The baseline file cannot be read, or is not an `analyze` JSON report whose issues carry an id and a severity.
 */
final class InvalidBaseline extends RuntimeException
{
}

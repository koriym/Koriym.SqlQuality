<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The --index value is not `CREATE INDEX name ON table (columns)` or `table(columns)` with plain identifiers. The message is the value.
 */
final class InvalidIndexDefinition extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The table already has an index of the name the trial would create. The message is the table and index name.
 */
final class IndexAlreadyExists extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The statement has a :name placeholder no parameter was given for.
 *
 * The message lists the missing names, comma separated, so a caller can supply them and retry.
 */
final class UnboundParameter extends RuntimeException
{
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The server cannot try an index without exposing it to other sessions: invisible indexes need MySQL 8.0 or later. The message names the server version.
 */
final class IndexTrialUnsupported extends RuntimeException
{
}

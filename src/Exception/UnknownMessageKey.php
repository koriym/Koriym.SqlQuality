<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The message keys ExplainAnalyzer accepts are the keys of DEFAULT_MESSAGES.
 *
 * An unknown key is never read, so a mistyped one would leave the default message
 * in place without a trace. The message is the rejected keys, comma-separated.
 */
final class UnknownMessageKey extends LogicException
{
}

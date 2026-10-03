<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * `SET SESSION TRANSACTION READ ONLY` only governs transactions started after
 * it runs; a transaction already open on the connection keeps whatever write
 * access it had when it began. Running inside one would make the read-only
 * guarantee cosmetic, so `ReadOnlySession::run()` refuses up front instead.
 */
final class ActiveTransactionRejected extends LogicException
{
}

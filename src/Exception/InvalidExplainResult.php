<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * The EXPLAIN FORMAT=JSON row is not a JSON object with a query_block.
 *
 * Detectors walk query_block, so an unexpected shape stops the analysis
 * instead of reporting no issues. The message is the raw EXPLAIN value.
 */
final class InvalidExplainResult extends RuntimeException
{
}

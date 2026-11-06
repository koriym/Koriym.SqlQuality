<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * A table the statement reads already has an invisible index. use_invisible_indexes=on would enable it
 * too, so the trial could not tell its index's effect apart. The message lists them as table.index.
 */
final class InvisibleIndexesPresent extends RuntimeException
{
}

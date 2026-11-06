<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * A list parameter is empty; `IN ()` is not valid SQL. The message is the parameter name.
 */
final class EmptyListParameter extends RuntimeException
{
}

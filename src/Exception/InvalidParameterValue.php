<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Exception;

/**
 * A parameter value cannot be written as a SQL literal: an object, or a list holding a list or an object.
 * The message starts with the parameter name.
 */
final class InvalidParameterValue extends RuntimeException
{
}

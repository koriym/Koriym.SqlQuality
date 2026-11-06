<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function class_exists;
use function constant;

/**
 * PDO MySQL attribute ids, from Pdo\Mysql (PHP 8.4+) where it exists, else from the PDO::MYSQL_ATTR_*
 * constants PHP 8.5 deprecates. Looked up by name, so neither spelling is touched on a PHP that lacks
 * or deprecates it.
 */
final class MysqlAttribute
{
    public static function useBufferedQuery(): int
    {
        return self::id('ATTR_USE_BUFFERED_QUERY', 'MYSQL_ATTR_USE_BUFFERED_QUERY');
    }

    public static function multiStatements(): int
    {
        return self::id('ATTR_MULTI_STATEMENTS', 'MYSQL_ATTR_MULTI_STATEMENTS');
    }

    private static function id(string $name, string $legacyName): int
    {
        /** @var int */
        return class_exists('Pdo\Mysql') ? constant('Pdo\Mysql::' . $name) : constant('PDO::' . $legacyName);
    }

    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function class_exists;
use function constant;

final class MysqlAttributeTest extends TestCase
{
    public function testTheIdsMatchTheDriverConstantsOfThisPhp(): void
    {
        $prefix = class_exists('Pdo\Mysql') ? 'Pdo\Mysql::ATTR_' : 'PDO::MYSQL_ATTR_';

        $this->assertSame(constant($prefix . 'USE_BUFFERED_QUERY'), MysqlAttribute::useBufferedQuery());
        $this->assertSame(constant($prefix . 'MULTI_STATEMENTS'), MysqlAttribute::multiStatements());
    }
}

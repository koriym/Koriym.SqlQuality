<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function getenv;

abstract class MySqlTestCase extends TestCase
{
    protected function connect(): PDO
    {
        $dsn = (string) (getenv('SQL_QUALITY_DSN') ?: 'mysql:host=127.0.0.1;dbname=test');
        $user = (string) (getenv('SQL_QUALITY_USER') ?: 'root');
        $password = (string) getenv('SQL_QUALITY_PASSWORD');

        try {
            return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            if ((string) getenv('SQL_QUALITY_REQUIRE_DB') !== '') {
                $this->fail($dsn . ': ' . $e->getMessage());
            }

            $this->markTestSkipped($dsn . ': ' . $e->getMessage());
        }
    }
}

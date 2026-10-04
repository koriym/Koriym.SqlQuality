<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function getenv;

abstract class MySqlTestCase extends TestCase
{
    /** @return array{dsn: string, user: string, password: string} */
    protected function connectionSettings(): array
    {
        return [
            'dsn' => (string) (getenv('SQL_QUALITY_DSN') ?: 'mysql:host=127.0.0.1;dbname=test'),
            'user' => (string) (getenv('SQL_QUALITY_USER') ?: 'root'),
            'password' => (string) getenv('SQL_QUALITY_PASSWORD'),
        ];
    }

    protected function connect(): PDO
    {
        ['dsn' => $dsn, 'user' => $user, 'password' => $password] = $this->connectionSettings();

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

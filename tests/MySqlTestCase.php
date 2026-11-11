<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function getenv;
use function random_bytes;

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

    /**
     * Creates a database of the test's own, named "<prefix>_<random hex>" and without IF NOT EXISTS, so dropping
     * it drops nothing else. Skipped without the CREATE privilege, unless SQL_QUALITY_REQUIRE_DB is set.
     */
    protected function createScratchDatabase(PDO $pdo, string $prefix): string
    {
        $name = $prefix . '_' . bin2hex(random_bytes(4));
        try {
            $pdo->exec("CREATE DATABASE {$name}");
        } catch (PDOException $e) {
            if ((string) getenv('SQL_QUALITY_REQUIRE_DB') !== '') {
                $this->fail("CREATE DATABASE {$name}: " . $e->getMessage());
            }

            $this->markTestSkipped("CREATE DATABASE {$name}: " . $e->getMessage());
        }

        return $name;
    }
}

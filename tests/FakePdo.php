<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Closure;
use PDO;

/**
 * Real PDO/PDOStatement mocks are environment-fragile: PHPUnit's mock generator
 * trips over driver-specific constants some PHP/pdo builds declare on PDO. A plain
 * subclass with a no-op constructor avoids that and only overrides what the tests drive.
 */
final class FakePdo extends PDO
{
    public function __construct(private readonly Closure $query, private readonly Closure $exec)
    {
    }

    public function query(string $query, int|null $fetchMode = null, mixed ...$fetchModeArgs): FakeStatement
    {
        return ($this->query)($query);
    }

    public function inTransaction(): bool
    {
        return false;
    }

    public function exec(string $statement): int
    {
        return ($this->exec)($statement);
    }
}

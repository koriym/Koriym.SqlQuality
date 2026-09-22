<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\ReadOnlySessionUnavailable;
use PDO;

final class ReadOnlySession
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param callable(): T $fn
     *
     * @return T
     *
     * @template T
     */
    public function run(callable $fn): mixed
    {
        $saved = $this->readOnlyFlag();
        $this->pdo->exec('SET SESSION TRANSACTION READ ONLY');

        try {
            return $fn();
        } finally {
            $this->pdo->exec('SET SESSION transaction_read_only = ' . $saved);
        }
    }

    private function readOnlyFlag(): string
    {
        $statement = $this->pdo->query('SELECT @@session.transaction_read_only');
        if ($statement === false) {
            /** @var string $driver */
            $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

            throw new ReadOnlySessionUnavailable($driver);
        }

        /** @var int|string|false $flag */
        $flag = $statement->fetchColumn();

        return (string) (int) $flag;
    }
}

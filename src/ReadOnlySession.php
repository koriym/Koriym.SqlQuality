<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\ReadOnlySessionUnavailable;
use PDO;
use PDOException;
use Throwable;

final class ReadOnlySession
{
    /** MariaDB before 11.1 exposes only tx_read_only; MySQL 8.0 accepts both. */
    private const VARIABLE_NAMES = ['transaction_read_only', 'tx_read_only'];

    private string $variableName = self::VARIABLE_NAMES[0];

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

        $original = null;

        try {
            return $fn();
        } catch (Throwable $e) {
            $original = $e;

            throw $e;
        } finally {
            try {
                $this->pdo->exec('SET SESSION ' . $this->variableName . ' = ' . $saved);
            } catch (Throwable $restoreError) {
                // A failed restore must not mask the exception $fn() threw.
                if ($original === null) {
                    throw $restoreError;
                }
            }
        }
    }

    private function readOnlyFlag(): string
    {
        foreach (self::VARIABLE_NAMES as $variableName) {
            try {
                $statement = $this->pdo->query('SELECT @@session.' . $variableName);
            } catch (PDOException) {
                continue;
            }

            if ($statement === false) {
                continue;
            }

            $this->variableName = $variableName;

            /** @var int|string|false $flag */
            $flag = $statement->fetchColumn();

            return (string) (int) $flag;
        }

        /** @var string $driver */
        $driver = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        throw new ReadOnlySessionUnavailable($driver);
    }
}

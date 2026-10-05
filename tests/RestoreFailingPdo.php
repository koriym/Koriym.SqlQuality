<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;

use function str_contains;
use function str_starts_with;

/** A live connection whose restore of optimizer_trace fails; enabling it still works */
final class RestoreFailingPdo extends PDO
{
    public const MESSAGE = 'optimizer_trace restore failed';

    /** @param array{dsn: string, user: string, password: string} $settings */
    public function __construct(array $settings)
    {
        parent::__construct($settings['dsn'], $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function exec(string $statement): int|false
    {
        if (str_starts_with($statement, 'SET optimizer_trace = ') && ! str_contains($statement, 'enabled=on')) {
            throw new PDOException(self::MESSAGE);
        }

        return parent::exec($statement);
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOStatement;

/** A live connection that records every statement sent through exec() and query() */
final class RecordingPdo extends PDO
{
    /** @var list<string> */
    public array $statements = [];

    /** @param array{dsn: string, user: string, password: string} $settings */
    public function __construct(array $settings)
    {
        parent::__construct($settings['dsn'], $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;

        return parent::exec($statement);
    }

    public function query(string $query, int|null $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->statements[] = $query;

        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

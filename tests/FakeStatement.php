<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDOStatement;

final class FakeStatement extends PDOStatement
{
    public function __construct(private readonly string $scalar)
    {
    }

    public function fetchColumn(int $column = 0): string
    {
        return $this->scalar;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Closure;
use PDO;

use function str_starts_with;

/** A live connection that runs a hook before or after exec() of a statement starting with a given prefix */
final class HookedPdo extends PDO
{
    /**
     * @param array{dsn: string, user: string, password: string} $settings
     * @param Closure(string): void                              $hook
     */
    public function __construct(
        array $settings,
        private readonly string $prefix,
        private readonly Closure $hook,
        private readonly bool $after = false,
    ) {
        parent::__construct($settings['dsn'], $settings['user'], $settings['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }

    public function exec(string $statement): int|false
    {
        $matches = str_starts_with($statement, $this->prefix);
        if ($matches && ! $this->after) {
            ($this->hook)($statement);
        }

        $result = parent::exec($statement);
        if ($matches && $this->after) {
            ($this->hook)($statement);
        }

        return $result;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Override;
use RuntimeException;

/** Optimizer settings whose disable and restore steps fail, to see which failure reaches the caller */
final class FailingOptimizerSettings implements OptimizerSettingsInterface
{
    public const DISABLE_MESSAGE = 'optimizer disable failed';
    public const RESTORE_MESSAGE = 'optimizer restore failed';

    public function __construct(private readonly bool $disableFails)
    {
    }

    #[Override]
    public function saveCurrentSettings(): array
    {
        return [];
    }

    #[Override]
    public function restore(array $settings): void
    {
        throw new RuntimeException(self::RESTORE_MESSAGE);
    }

    #[Override]
    public function disableAll(): void
    {
        if ($this->disableFails) {
            throw new RuntimeException(self::DISABLE_MESSAGE);
        }
    }

    #[Override]
    public function enableAll(): void
    {
    }
}

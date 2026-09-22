<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;

/**
 * @psalm-immutable
 * @psalm-import-type WarningSeverity from Types
 * @psalm-import-type Suggestion from Types
 */
final class Finding
{
    /**
     * @param array<string, mixed> $evidence   Values the detector looked at; table_name when the finding is about one table
     * @param WarningSeverity|null $severity   null takes the default for the warning type
     * @param Suggestion|null      $suggestion
     */
    public function __construct(
        public readonly array $evidence,
        public readonly string|null $severity = null,
        public readonly float|null $confidence = null,
        public readonly array|null $suggestion = null,
    ) {
    }
}

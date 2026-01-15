<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Override;

use function is_string;
use function preg_match;

final class FunctionInvalidatesIndexDetector implements DetectorInterface
{
    #[Override]
    public function detect(array $explainResult): bool
    {
        if (! isset($explainResult['query_block']['table'])) {
            return false;
        }

        return $this->hasAttachedConditionWithFunction($explainResult['query_block']['table']);
    }

    private function hasAttachedConditionWithFunction(array $table): bool
    {
        if (! isset($table['attached_condition']) || ! is_string($table['attached_condition'])) {
            return false;
        }

        // CASEやその他の関数も必要に応じて追加
        return (bool) preg_match('/\b(?:CAST|CONVERT|DATE|SUBSTRING|CONCAT)\s*\(/i', $table['attached_condition']);
    }
}

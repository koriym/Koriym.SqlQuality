<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Override;

use function is_string;
use function preg_match;

final class FunctionInvalidatesIndexDetector implements DetectorInterface
{
    #[Override]
    public function detect(array $explainResult): bool
    {
        $walker = new ExplainWalker();
        foreach ($walker->tables($explainResult) as $table) {
            if ($this->hasAttachedConditionWithFunction($table)) {
                return true;
            }
        }

        return false;
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

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Override;

use function array_flip;
use function array_intersect_key;
use function is_string;
use function preg_match;

final class FunctionInvalidatesIndexDetector implements DetectorInterface
{
    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($this->hasAttachedConditionWithFunction($table)) {
                $findings[] = new Finding(array_intersect_key($table, array_flip(['table_name', 'attached_condition'])));
            }
        }

        return $findings;
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

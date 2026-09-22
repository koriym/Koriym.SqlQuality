<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function count;
use function preg_match;

/** @psalm-import-type Suggestion from Types */
final class IneffectiveUnionDetector implements DetectorInterface
{
    private const UNION_WITHOUT_ALL = '/\bUNION\b(?!\s+ALL\b)/i';

    #[Override]
    public function detect(QueryContext $context): array
    {
        if (! isset($context->explain['query_block']['union_result'])) {
            return [];
        }

        $unionResult = $context->explain['query_block']['union_result'];
        if (($unionResult['using_temporary_table'] ?? false) !== true || ($unionResult['using_filesort'] ?? false) !== true) {
            return [];
        }

        return [
            new Finding(
                evidence: [
                    'using_temporary_table' => true,
                    'using_filesort' => true,
                    'query_specifications_count' => count($unionResult['query_specifications'] ?? []),
                ],
                suggestion: $this->suggest($context->sql),
            ),
        ];
    }

    /** @return Suggestion */
    private function suggest(string $sql): array
    {
        if (preg_match(self::UNION_WITHOUT_ALL, $sql) === 1) {
            return ['kind' => 'rewrite', 'description' => 'UNION deduplicates rows via a temporary table and filesort; use UNION ALL if duplicates cannot occur or are acceptable.'];
        }

        return ['kind' => 'review', 'description' => 'UNION result needs a temporary table and filesort to remove duplicates; review whether the branches can be made not to overlap.'];
    }
}

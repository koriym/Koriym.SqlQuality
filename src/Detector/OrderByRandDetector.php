<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function preg_match;

/**
 * @psalm-immutable
 * @psalm-import-type ExplainOperation from Types
 */
final class OrderByRandDetector implements DetectorInterface
{
    private const CRITICAL_ROWS_EXAMINED = 10000;
    private const ORDER_BY_RAND_PATTERN = '/ORDER\s+BY\s+RAND\s*\(/i';

    #[Override]
    public function detect(QueryContext $context): array
    {
        if (preg_match(self::ORDER_BY_RAND_PATTERN, $context->sql) !== 1) {
            return [];
        }

        return [$this->finding($context)];
    }

    private function finding(QueryContext $context): Finding
    {
        $orderingOperation = $context->explain['query_block']['ordering_operation'] ?? null;
        $rowsExamined = $orderingOperation['table']['rows_examined_per_scan'] ?? null;

        return new Finding(
            [
                'rows_examined_per_scan' => $rowsExamined,
                'using_temporary_table' => $orderingOperation['using_temporary_table'] ?? null,
                'using_filesort' => $orderingOperation['using_filesort'] ?? null,
            ],
            severity: $rowsExamined !== null && (float) $rowsExamined >= self::CRITICAL_ROWS_EXAMINED ? 'Critical' : null,
            suggestion: [
                'kind' => 'rewrite',
                'description' => 'Replace ORDER BY RAND() with a random primary-key range scan, e.g. WHERE id >= (SELECT FLOOR(RAND() * MAX(id)) FROM …) ORDER BY id LIMIT n.',
            ],
        );
    }
}

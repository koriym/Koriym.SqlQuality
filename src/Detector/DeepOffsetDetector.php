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
final class DeepOffsetDetector implements DetectorInterface
{
    private const MIN_OFFSET = 1000;
    private const CRITICAL_OFFSET = 100000;

    private const LIMIT_OFFSET_PATTERN = '/LIMIT\s+(?:(\d+)\s*,\s*(\d+)|(\d+)\s+OFFSET\s+(\d+))/i';

    #[Override]
    public function detect(QueryContext $context): array
    {
        $offsetLimit = $this->offsetAndLimit($context->sql);
        if ($offsetLimit === null || $offsetLimit['offset'] < self::MIN_OFFSET) {
            return [];
        }

        return [$this->finding($context, $offsetLimit['offset'], $offsetLimit['limit'])];
    }

    /** @return array{offset: int, limit: int}|null */
    private function offsetAndLimit(string $sql): array|null
    {
        if (preg_match(self::LIMIT_OFFSET_PATTERN, $sql, $matches) !== 1) {
            return null;
        }

        if (($matches[1] ?? '') !== '') {
            return ['offset' => (int) $matches[1], 'limit' => (int) $matches[2]];
        }

        return ['offset' => (int) $matches[4], 'limit' => (int) $matches[3]];
    }

    private function finding(QueryContext $context, int $offset, int $limit): Finding
    {
        return new Finding(
            [
                'offset' => $offset,
                'limit' => $limit,
                'ordering' => $this->ordering($context),
            ],
            severity: $offset >= self::CRITICAL_OFFSET ? 'Critical' : null,
            suggestion: [
                'kind' => 'rewrite',
                'description' => 'Rewrite as keyset pagination: WHERE (sort_col, id) < (:last_sort, :last_id) ORDER BY sort_col DESC, id DESC LIMIT n instead of a growing OFFSET.',
            ],
        );
    }

    /** @return array{using_filesort: bool|null, rows_examined_per_scan: int|numeric-string|null}|null */
    private function ordering(QueryContext $context): array|null
    {
        $orderingOperation = $context->explain['query_block']['ordering_operation'] ?? null;
        if ($orderingOperation === null) {
            return null;
        }

        return [
            'using_filesort' => $orderingOperation['using_filesort'] ?? null,
            'rows_examined_per_scan' => $orderingOperation['table']['rows_examined_per_scan'] ?? null,
        ];
    }
}

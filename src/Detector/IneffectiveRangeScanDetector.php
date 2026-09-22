<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function sprintf;
use function str_starts_with;

/** @psalm-import-type ExplainTable from Types */
final class IneffectiveRangeScanDetector implements DetectorInterface
{
    /** A range scan examining fewer rows than this is not reported */
    private const MIN_ROWS_EXAMINED = 1000;

    /** A range scan keeping this ratio of the examined rows or more is not reported */
    private const MAX_FILTERED = 20.0;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if (str_starts_with($table['table_name'], '<') || ! $this->isIneffective($table)) {
                continue;
            }

            $findings[] = new Finding(
                evidence: [
                    'table_name' => $table['table_name'],
                    'access_type' => $table['access_type'],
                    'key' => $table['key'] ?? null,
                    'possible_keys' => $table['possible_keys'] ?? [],
                    'rows_examined_per_scan' => (int) ($table['rows_examined_per_scan'] ?? 0),
                    'filtered' => $table['filtered'] ?? null,
                    'attached_condition' => $table['attached_condition'] ?? null,
                ],
                suggestion: ['kind' => 'review', 'description' => $this->description($table)],
            );
        }

        return $findings;
    }

    /** @param ExplainTable $table */
    private function isIneffective(array $table): bool
    {
        if ($table['access_type'] === 'index_merge') {
            return true;
        }

        return $table['access_type'] === 'range'
            && (int) ($table['rows_examined_per_scan'] ?? 0) >= self::MIN_ROWS_EXAMINED
            && (float) ($table['filtered'] ?? 100) < self::MAX_FILTERED;
    }

    /** @param ExplainTable $table */
    private function description(array $table): string
    {
        if ($table['access_type'] === 'index_merge') {
            return sprintf('%s is read by merging %s; a composite index over the compared columns would serve the query with one index.', $table['table_name'], $table['key'] ?? 'several indexes');
        }

        return sprintf('The range scan on %s examines %d rows and keeps %s%%; a composite index with the equality columns first and the range column last would narrow the scan.', $table['key'] ?? $table['table_name'], (int) ($table['rows_examined_per_scan'] ?? 0), (string) ($table['filtered'] ?? '?'));
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_column;
use function array_flip;
use function array_intersect_key;
use function str_starts_with;

/** @psalm-import-type ExplainTable from Types */
final class FullTableScanDetector implements DetectorInterface
{
    /** Below this row count a full scan is cheap enough to report as Info rather than the default Critical */
    private const LOW_VOLUME_ROWS = 100;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($table['access_type'] !== 'ALL' || str_starts_with($table['table_name'], '<')) {
                continue;
            }

            $findings[] = new Finding(
                evidence: array_intersect_key($table, array_flip(['table_name', 'rows_examined_per_scan', 'filtered', 'possible_keys', 'key', 'attached_condition'])),
                severity: $this->isLowVolume($table) ? 'Info' : null,
                suggestion: $this->suggest($context, $table),
            );
        }

        return $findings;
    }

    /** @param ExplainTable $table */
    private function isLowVolume(array $table): bool
    {
        return (float) ($table['rows_examined_per_scan'] ?? 0) < self::LOW_VOLUME_ROWS;
    }

    /** @param ExplainTable $table */
    private function suggest(QueryContext $context, array $table): array|null
    {
        $attachedCondition = $table['attached_condition'] ?? null;
        if ($attachedCondition !== null) {
            $groups = ConditionColumns::forAlias($attachedCondition, $table['table_name']);
            $columns = array_column($groups['equality'], 'column');
            if ($groups['range'] !== []) {
                $columns[] = $groups['range'][0]['column'];
            }

            $suggestion = IndexSuggestion::create($context, $table['table_name'], $columns);
            if ($suggestion !== null) {
                return $suggestion;
            }
        }

        $possibleKeys = $table['possible_keys'] ?? [];
        if ($possibleKeys !== [] && ($table['key'] ?? null) === null) {
            return ['kind' => 'review', 'description' => 'An index exists but is not used; check selectivity or statistics.'];
        }

        return null;
    }
}

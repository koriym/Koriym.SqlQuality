<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function in_array;
use function sprintf;

/** @psalm-import-type ExplainTable from Types */
final class LowCardinalityIndexDetector implements DetectorInterface
{
    /** Distinct values per row of the leading key column; raising it reports lookups on more selective columns. */
    private const MAX_SELECTIVITY = 0.01;

    /** Lookups examining fewer rows than this are not reported; lowering it reports cheaper lookups on the same columns. */
    private const ROW_THRESHOLD = 500;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            $finding = $this->lowCardinalityLookup($context, $table);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /** @param ExplainTable $table */
    private function lowCardinalityLookup(QueryContext $context, array $table): Finding|null
    {
        if (! in_array($table['access_type'], ['ref', 'range'], true) || ! isset($table['key'], $table['used_key_parts'][0])) {
            return null;
        }

        $rowsExamined = (int) ($table['rows_examined_per_scan'] ?? 0);
        if ($rowsExamined < self::ROW_THRESHOLD) {
            return null;
        }

        $cardinality = $this->leadingColumnCardinality($context, $table['table_name'], $table['key']);
        $tableRows = (int) ($context->schemaFor($table['table_name'])['status']['table_rows'] ?? 0);
        if ($cardinality === null || $cardinality <= 0 || $tableRows <= 0 || $cardinality / $tableRows > self::MAX_SELECTIVITY) {
            return null;
        }

        $column = $table['used_key_parts'][0];

        return new Finding(
            evidence: [
                'table_name' => $table['table_name'],
                'key' => $table['key'],
                'column' => $column,
                'cardinality' => $cardinality,
                'table_rows' => $tableRows,
                'rows_examined_per_scan' => $rowsExamined,
                'filtered' => $table['filtered'] ?? null,
            ],
            suggestion: ['kind' => 'review', 'description' => sprintf('%s leads with %s, which has %d distinct values over %d rows; consider a composite index led by a more selective column, or whether this index is needed.', $table['key'], $column, $cardinality, $tableRows)],
        );
    }

    /** @return int|null CARDINALITY of the index's first column; null when the schema does not know the index */
    private function leadingColumnCardinality(QueryContext $context, string $aliasOrTable, string $key): int|null
    {
        foreach ($context->schemaFor($aliasOrTable)['indexes'] ?? [] as $index) {
            if ($index['INDEX_NAME'] === $key && $index['SEQ_IN_INDEX'] === 1) {
                return $index['CARDINALITY'];
            }
        }

        return null;
    }
}

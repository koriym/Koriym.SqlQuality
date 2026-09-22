<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_flip;
use function array_intersect_key;
use function array_slice;
use function in_array;
use function preg_match_all;

use const PREG_SET_ORDER;

/**
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type Suggestion from Types
 */
final class IneffectiveJoinDetector implements DetectorInterface
{
    private const JOIN_PREDICATE = '/`\w+`\.`(?<left>\w+)`\.`(?<leftColumn>\w+)`\s*=\s*`\w+`\.`(?<right>\w+)`\.`(?<rightColumn>\w+)`/';

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->nestedLoops() as $members) {
            // The first member drives the loop; its full scan belongs to FullTableScanDetector.
            foreach (array_slice($members, 1) as $table) {
                if (! $this->isIneffectiveJoin($table)) {
                    continue;
                }

                $findings[] = new Finding(
                    evidence: array_intersect_key($table, array_flip(['table_name', 'access_type', 'using_join_buffer', 'rows_examined_per_scan', 'rows_produced_per_join', 'attached_condition', 'ref'])),
                    suggestion: $this->suggest($context, $table),
                );
            }
        }

        return $findings;
    }

    /** @param ExplainTable $table */
    private function isIneffectiveJoin(array $table): bool
    {
        return in_array($table['access_type'], ['ALL', 'index'], true) || isset($table['using_join_buffer']);
    }

    /**
     * @param ExplainTable $table
     *
     * @return Suggestion
     */
    private function suggest(QueryContext $context, array $table): array
    {
        $suggestion = IndexSuggestion::create($context, $table['table_name'], $this->joinColumns($table));
        if ($suggestion !== null) {
            return $suggestion;
        }

        return ['kind' => 'review', 'description' => 'No index is used for this join; check the join condition.'];
    }

    /**
     * @param ExplainTable $table
     *
     * @return list<string> this table's columns compared for equality with another table's columns
     */
    private function joinColumns(array $table): array
    {
        preg_match_all(self::JOIN_PREDICATE, $table['attached_condition'] ?? '', $matches, PREG_SET_ORDER);
        $columns = [];
        foreach ($matches as $match) {
            if ($match['left'] === $table['table_name']) {
                $columns[] = $match['leftColumn'];
            }

            if ($match['right'] === $table['table_name']) {
                $columns[] = $match['rightColumn'];
            }
        }

        return $columns;
    }
}

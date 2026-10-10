<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_slice;
use function in_array;
use function preg_match;
use function preg_quote;

/**
 * @psalm-immutable
 * @psalm-import-type ExplainTable from Types
 */
final class CartesianProductDetector implements DetectorInterface
{
    private const CRITICAL_ROWS_PRODUCED = 100000;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->nestedLoops() as $group) {
            foreach ($group as $offset => $table) {
                if ($offset === 0 || isset($table['table_function']) || ! $this->isCartesianProduct($table, $group, $offset)) {
                    continue;
                }

                $findings[] = $this->finding($table, $group, $offset);
            }
        }

        return $findings;
    }

    /**
     * @param ExplainTable       $table
     * @param list<ExplainTable> $group
     */
    private function isCartesianProduct(array $table, array $group, int $offset): bool
    {
        return $this->refIsMissingOrConst($table)
            && ! $this->attachedConditionReferencesPreceding($table, $group, $offset)
            && ! in_array($table['access_type'], ['eq_ref', 'const', 'system'], true)
            && ! $this->allPrecedingAreConstOrSystem($group, $offset);
    }

    /** @param list<ExplainTable> $group */
    private function allPrecedingAreConstOrSystem(array $group, int $offset): bool
    {
        foreach (array_slice($group, 0, $offset) as $member) {
            if (! in_array($member['access_type'], ['const', 'system'], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param ExplainTable $table */
    private function refIsMissingOrConst(array $table): bool
    {
        foreach ($table['ref'] ?? [] as $source) {
            if ($source !== 'const') {
                return false;
            }
        }

        return true;
    }

    /**
     * attached_condition can only name tables already bound earlier in the same
     * nested loop, so a match against a preceding alias is enough to rule out
     * a Cartesian product (a later alias could never appear here).
     *
     * @param ExplainTable       $table
     * @param list<ExplainTable> $group
     */
    private function attachedConditionReferencesPreceding(array $table, array $group, int $offset): bool
    {
        $condition = $table['attached_condition'] ?? null;
        if ($condition === null) {
            return false;
        }

        foreach ($this->precedingTables($group, $offset) as $alias) {
            if (preg_match('/`[^`]+`\.`' . preg_quote($alias, '/') . '`\./', $condition) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<ExplainTable> $group
     *
     * @return list<string>
     */
    private function precedingTables(array $group, int $offset): array
    {
        $tables = [];
        foreach (array_slice($group, 0, $offset) as $member) {
            $tables[] = $member['table_name'];
        }

        return $tables;
    }

    /**
     * @param ExplainTable       $table
     * @param list<ExplainTable> $group
     */
    private function finding(array $table, array $group, int $offset): Finding
    {
        $rowsProduced = $table['rows_produced_per_join'] ?? null;

        return new Finding(
            [
                'table_name' => $table['table_name'],
                'access_type' => $table['access_type'],
                'ref' => $table['ref'] ?? null,
                'rows_examined_per_scan' => $table['rows_examined_per_scan'] ?? null,
                'rows_produced_per_join' => $rowsProduced,
                'preceding_tables' => $this->precedingTables($group, $offset),
                'attached_condition' => $table['attached_condition'] ?? null,
            ],
            severity: $rowsProduced !== null && (float) $rowsProduced >= self::CRITICAL_ROWS_PRODUCED ? 'Critical' : null,
            suggestion: ['kind' => 'review', 'description' => 'Missing join condition; confirm whether the ON clause was omitted or a CROSS JOIN is intended.'],
        );
    }
}

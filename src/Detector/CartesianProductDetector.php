<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_int;
use function preg_match;
use function preg_quote;

/**
 * @psalm-immutable
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type ExplainTableAccess from Types
 */
final class CartesianProductDetector implements DetectorInterface
{
    private const CRITICAL_ROWS_PRODUCED = 100000;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($this->nestedLoopGroups($context) as $group) {
            foreach ($group as $offset => $access) {
                if ($offset === 0 || ! $this->isCartesianProduct($access['table'], $group, $offset)) {
                    continue;
                }

                $findings[] = $this->finding($access['table'], $group, $offset);
            }
        }

        return $findings;
    }

    /** @return list<list<ExplainTableAccess>> grouped in nested_loop member order */
    private function nestedLoopGroups(QueryContext $context): array
    {
        $groups = [];
        foreach ($context->tableAccesses() as $access) {
            $key = $this->nestedLoopGroupKey($access['path']);
            if ($key === null) {
                continue;
            }

            $groups[$key][] = $access;
        }

        return array_values($groups);
    }

    /**
     * @param list<array-key> $path
     *
     * @psalm-pure
     */
    private function nestedLoopGroupKey(array $path): string|null
    {
        if (count($path) < 3) {
            return null;
        }

        $tableOffset = count($path) - 1;
        $memberOffset = count($path) - 2;
        $nestedLoopOffset = count($path) - 3;
        if (
            $path[$nestedLoopOffset] !== 'nested_loop' ||
            ! is_int($path[$memberOffset]) ||
            $path[$tableOffset] !== 'table'
        ) {
            return null;
        }

        $groupPath = array_slice($path, 0, $nestedLoopOffset + 1);

        return implode("\0", array_map(static fn (int|string $part): string => (string) $part, $groupPath));
    }

    /**
     * @param ExplainTable             $table
     * @param list<ExplainTableAccess> $group
     */
    private function isCartesianProduct(array $table, array $group, int $offset): bool
    {
        return $this->refIsMissingOrConst($table)
            && ! $this->attachedConditionReferencesPreceding($table, $group, $offset)
            && $table['access_type'] !== 'eq_ref';
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
     * @param ExplainTable             $table
     * @param list<ExplainTableAccess> $group
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
     * @param list<ExplainTableAccess> $group
     *
     * @return list<string>
     */
    private function precedingTables(array $group, int $offset): array
    {
        $tables = [];
        foreach (array_slice($group, 0, $offset) as $access) {
            $tables[] = $access['table']['table_name'];
        }

        return $tables;
    }

    /**
     * @param ExplainTable             $table
     * @param list<ExplainTableAccess> $group
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

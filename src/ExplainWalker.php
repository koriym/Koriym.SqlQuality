<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function is_array;

/**
 * Traverses MySQL EXPLAIN FORMAT=JSON structures.
 *
 * EXPLAIN JSON can place table access under query_block.table,
 * nested_loop[*].table, ordering_operation, grouping_operation,
 * duplicates_removal, union_result, and subqueries. This walker keeps
 * detectors from re-implementing ad-hoc recursion for each shape.
 *
 * @psalm-immutable
 */
final class ExplainWalker
{
    /**
     * @param array<string, mixed> $explainResult
     *
     * @return list<array{path: list<array-key>, table: array<string, mixed>}>
     *
     * @psalm-mutation-free
     */
    public function tableAccesses(array $explainResult): array
    {
        return $this->collectTableAccesses($explainResult, []);
    }

    /**
     * @param array<string, mixed> $explainResult
     *
     * @return list<array<string, mixed>>
     *
     * @psalm-mutation-free
     */
    public function tables(array $explainResult): array
    {
        $tables = [];
        foreach ($this->tableAccesses($explainResult) as $access) {
            $tables[] = $access['table'];
        }

        return $tables;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @psalm-mutation-free
     */
    public function contains(array $node, string $key, mixed $value): bool
    {
        foreach ($node as $nodeKey => $nodeValue) {
            if ($nodeKey === $key && $nodeValue === $value) {
                return true;
            }

            if (is_array($nodeValue)) {
                /** @var array<string, mixed> $child */
                $child = $nodeValue;
                if ($this->contains($child, $key, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array-key>      $path
     *
     * @return list<array{path: list<array-key>, table: array<string, mixed>}>
     *
     * @psalm-mutation-free
     */
    private function collectTableAccesses(array $node, array $path): array
    {
        $accesses = [];
        if (isset($node['table_name'], $node['access_type'])) {
            $accesses[] = ['path' => $path, 'table' => $node];
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            /** @var array<string, mixed> $child */
            $child = $value;
            foreach ($this->collectTableAccesses($child, [...$path, $key]) as $access) {
                $accesses[] = $access;
            }
        }

        return $accesses;
    }
}

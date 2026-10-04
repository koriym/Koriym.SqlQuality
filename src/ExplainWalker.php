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
 * @psalm-import-type ExplainNode from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type ExplainTableAccess from Types
 */
final class ExplainWalker
{
    /**
     * @param ExplainNode $explainResult
     *
     * @return list<ExplainTableAccess>
     *
     * @psalm-mutation-free
     */
    public function tableAccesses(array $explainResult): array
    {
        return $this->collectTableAccesses($explainResult, []);
    }

    /**
     * @param ExplainNode $explainResult
     *
     * @return list<ExplainTable>
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
     * @param ExplainNode $node
     *
     * @psalm-mutation-free
     */
    public function contains(array $node, string $key, mixed $value): bool
    {
        return $this->pathOf($node, $key, $value) !== null;
    }

    /**
     * @param ExplainNode $node
     *
     * @return list<array-key>|null path of the first node holding $key => $value
     *
     * @psalm-mutation-free
     */
    public function pathOf(array $node, string $key, mixed $value): array|null
    {
        return $this->findPath($node, $key, $value, []);
    }

    /**
     * @param ExplainNode     $node
     * @param list<array-key> $path
     *
     * @return list<array-key>|null
     *
     * @psalm-mutation-free
     */
    private function findPath(array $node, string $key, mixed $value, array $path): array|null
    {
        foreach ($node as $nodeKey => $nodeValue) {
            if ($nodeKey === $key && $nodeValue === $value) {
                return $path;
            }

            if (! is_array($nodeValue)) {
                continue;
            }

            /** @var ExplainNode $child */
            $child = $nodeValue;
            $found = $this->findPath($child, $key, $value, [...$path, $nodeKey]);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param ExplainNode     $node
     * @param list<array-key> $path
     *
     * @return list<ExplainTableAccess>
     *
     * @psalm-mutation-free
     */
    private function collectTableAccesses(array $node, array $path): array
    {
        $accesses = [];
        if (isset($node['table_name'], $node['access_type'])) {
            /** @var ExplainTable $table */
            $table = $node;
            $accesses[] = ['path' => $path, 'table' => $table];
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            /** @var ExplainNode $child */
            $child = $value;
            foreach ($this->collectTableAccesses($child, [...$path, $key]) as $access) {
                $accesses[] = $access;
            }
        }

        return $accesses;
    }
}

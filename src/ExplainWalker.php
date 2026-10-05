<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_int;

/**
 * Enumerates table access wherever EXPLAIN FORMAT=JSON places it: query_block.table,
 * nested_loop[*].table, ordering_operation, grouping_operation, duplicates_removal,
 * union_result and subqueries.
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
     * @param ExplainNode $explainResult
     *
     * @return list<list<ExplainTable>> tables of each nested_loop in member order
     *
     * @psalm-mutation-free
     */
    public function nestedLoops(array $explainResult): array
    {
        $loops = [];
        foreach ($this->tableAccesses($explainResult) as $access) {
            $key = $this->nestedLoopKey($access['path']);
            if ($key === null) {
                continue;
            }

            $loops[$key][] = $access['table'];
        }

        return array_values($loops);
    }

    /**
     * The select_id of the query_block enclosing the node at $path; the root's select_id (or 0) when none is nested.
     *
     * @param ExplainNode     $queryBlock
     * @param list<array-key> $path       as reported by tableAccesses()
     *
     * @psalm-mutation-free
     */
    public function selectId(array $queryBlock, array $path): int
    {
        $select = is_int($queryBlock['select_id'] ?? null) ? $queryBlock['select_id'] : 0;
        $node = $queryBlock;
        foreach ($path as $key) {
            $child = $node[$key] ?? null;
            if (! is_array($child)) {
                break;
            }

            /** @var ExplainNode $node */
            $node = $child;
            if (is_int($node['select_id'] ?? null)) {
                $select = $node['select_id'];
            }
        }

        return $select;
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

    /**
     * @param list<array-key> $path
     *
     * @return string|null the nested_loop a path of the form [..., 'nested_loop', int, 'table'] belongs to
     *
     * @psalm-mutation-free
     */
    private function nestedLoopKey(array $path): string|null
    {
        $depth = count($path);
        if ($depth < 3 || $path[$depth - 3] !== 'nested_loop' || ! is_int($path[$depth - 2]) || $path[$depth - 1] !== 'table') {
            return null;
        }

        return implode("\0", array_map(static fn (int|string $part): string => (string) $part, array_slice($path, 0, $depth - 2)));
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_column;
use function array_slice;
use function in_array;
use function is_array;
use function is_int;
use function str_contains;

/**
 * @psalm-immutable
 * @psalm-import-type ExplainNode from Types
 */
final class DependentSubqueryDetector implements DetectorInterface
{
    private const CRITICAL_OUTER_ROWS = 1000;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($this->dependentPaths($context->explain['query_block'], []) as $path) {
            $findings[] = $this->finding($context, $path);
        }

        return $findings;
    }

    /**
     * @param ExplainNode     $node
     * @param list<array-key> $path
     *
     * @return list<list<array-key>>
     */
    private function dependentPaths(array $node, array $path): array
    {
        $paths = [];
        // Note 1276 alone is not evidence: an EXISTS usually becomes a semijoin without "dependent"
        if (($node['dependent'] ?? false) === true) {
            $paths[] = $path;
        }

        foreach ($node as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            /** @var ExplainNode $child */
            $child = $value;
            foreach ($this->dependentPaths($child, [...$path, $key]) as $found) {
                $paths[] = $found;
            }
        }

        return $paths;
    }

    /** @param list<array-key> $path */
    private function finding(QueryContext $context, array $path): Finding
    {
        $subquery = $this->nodeAt($context->explain['query_block'], $path);
        $queryBlock = $subquery['query_block'] ?? null;
        $selectId = is_array($queryBlock) ? $queryBlock['select_id'] ?? null : null;
        $outerRows = $this->outerRows($this->nodeAt($context->explain['query_block'], $this->enclosingBlockPath($path)));

        return new Finding(
            [
                'select_id' => is_int($selectId) ? $selectId : null,
                'path' => $path,
                'outer_rows' => $outerRows,
                'subquery_tables' => array_column((new ExplainWalker())->tables($subquery), 'table_name'),
                'warning' => $this->warning($context, is_int($selectId) ? $selectId : null),
            ],
            severity: $outerRows !== null && (float) $outerRows >= self::CRITICAL_OUTER_ROWS ? 'Critical' : null,
            suggestion: ['kind' => 'rewrite', 'description' => 'Rewrite the correlated subquery as a JOIN with GROUP BY or as a derived table.'],
        );
    }

    /**
     * @param ExplainNode     $node
     * @param list<array-key> $path
     *
     * @return ExplainNode
     */
    private function nodeAt(array $node, array $path): array
    {
        foreach ($path as $key) {
            /** @var ExplainNode $node */
            $node = $node[$key];
        }

        return $node;
    }

    /**
     * @param list<array-key> $path
     *
     * @return list<array-key> path of the nearest query_block above $path; [] is the root block
     */
    private function enclosingBlockPath(array $path): array
    {
        $blockPath = [];
        foreach ($path as $offset => $key) {
            if ($key === 'query_block') {
                $blockPath = array_slice($path, 0, $offset + 1);
            }
        }

        return $blockPath;
    }

    /**
     * @param ExplainNode $block
     *
     * @return int|numeric-string|null
     */
    private function outerRows(array $block): int|string|null
    {
        $max = null;
        foreach ((new ExplainWalker())->tableAccesses($block) as $access) {
            if (in_array('query_block', $access['path'], true)) {
                continue;
            }

            $rows = $access['table']['rows_produced_per_join'] ?? $access['table']['rows_examined_per_scan'] ?? null;
            if ($rows === null || ($max !== null && (float) $rows <= (float) $max)) {
                continue;
            }

            $max = $rows;
        }

        return $max;
    }

    private function warning(QueryContext $context, int|null $selectId): string|null
    {
        $messages = array_column($context->warningsWithCode(1276), 'Message');
        if ($selectId === null) {
            return $messages[0] ?? null;
        }

        foreach ($messages as $message) {
            if (str_contains($message, "of SELECT #{$selectId} ")) {
                return $message;
            }
        }

        return null;
    }
}

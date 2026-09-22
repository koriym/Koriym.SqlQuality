<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function explode;
use function intdiv;
use function preg_match;
use function rtrim;
use function strlen;
use function substr;
use function trim;

/**
 * @psalm-immutable
 * @psalm-import-type AnalyzeNode from Types
 */
final class ExplainAnalyzeParser
{
    private const NUMBER = '[0-9]+(?:\.[0-9]+)?(?:e[+-]?[0-9]+)?';
    private const LINE = '/^( *)-> (.*)$/';
    private const NEVER_EXECUTED = '/\s*\(never executed\)$/';
    private const ACTUAL = '/\s*\(actual time=(' . self::NUMBER . ')\.\.(' . self::NUMBER . ') rows=(' . self::NUMBER . ') loops=([0-9]+)\)$/';
    private const COST = '/\s*\(cost=(?:' . self::NUMBER . '\.\.)?(' . self::NUMBER . ') rows=(' . self::NUMBER . ')\)$/';

    /** @return list<AnalyzeNode> in order of appearance */
    public function parse(string $text): array
    {
        $nodes = [];
        foreach (explode("\n", $text) as $line) {
            $node = $this->parseLine(rtrim($line));
            if ($node === null) {
                continue;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }

    /** @return AnalyzeNode|null */
    private function parseLine(string $line): array|null
    {
        if (preg_match(self::LINE, $line, $match) !== 1) {
            return null;
        }

        $rest = $match[2];
        $node = [
            'depth' => intdiv(strlen($match[1]), 4),
            'operation' => '',
            'estimated_cost' => null,
            'estimated_rows' => null,
            'actual_time_first' => null,
            'actual_time_last' => null,
            'actual_rows' => null,
            'loops' => null,
            'never_executed' => false,
        ];

        if (preg_match(self::NEVER_EXECUTED, $rest, $suffix) === 1) {
            $node['never_executed'] = true;
            $rest = self::withoutSuffix($rest, $suffix[0]);
        }

        if (preg_match(self::ACTUAL, $rest, $suffix) === 1) {
            $node['actual_time_first'] = (float) $suffix[1];
            $node['actual_time_last'] = (float) $suffix[2];
            $node['actual_rows'] = (float) $suffix[3];
            $node['loops'] = (int) $suffix[4];
            $rest = self::withoutSuffix($rest, $suffix[0]);
        }

        if (preg_match(self::COST, $rest, $suffix) === 1) {
            $node['estimated_cost'] = (float) $suffix[1];
            $node['estimated_rows'] = (float) $suffix[2];
            $rest = self::withoutSuffix($rest, $suffix[0]);
        }

        $node['operation'] = trim($rest);

        return $node;
    }

    /** @psalm-pure */
    private static function withoutSuffix(string $text, string $suffix): string
    {
        return substr($text, 0, strlen($text) - strlen($suffix));
    }
}

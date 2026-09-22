<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use function in_array;
use function preg_match;
use function preg_match_all;
use function str_starts_with;
use function strtolower;
use function trim;

use const PREG_SET_ORDER;

/**
 * Classifies the columns of one table's EXPLAIN attached_condition by how the predicate compares them:
 * equality (=, in), range (>, <, >=, <=, between), a leading-wildcard LIKE, or wrapped in a function.
 * A condition joined by a top-level OR yields no columns in any group, since none of them hold on their own.
 *
 * @psalm-type ConditionColumnMatch = array{column: string, operator: string, literal: string|null, function: string|null}
 */
final class ConditionColumns
{
    private const OR_JOINED = '/\)\s*or\s*\(/i';
    private const FUNCTION_WRAPPED = '/(?<function>\w+)\(\s*`\w+`\.`(?<qualifier>\w+)`\.`(?<column>\w+)`/i';
    private const PREDICATE = '/`\w+`\.`(?<qualifier>\w+)`\.`(?<column>\w+)`\s*(?<operator>>=|<=|<>|!=|=|>|<|in\s*\(|between|like|is)\s*(?<literal>[^)]*)/i';

    /** @return array{equality: list<ConditionColumnMatch>, range: list<ConditionColumnMatch>, leadingWildcard: list<ConditionColumnMatch>, functionWrapped: list<ConditionColumnMatch>} */
    public static function forAlias(string $attachedCondition, string $aliasOrTable): array
    {
        $groups = ['equality' => [], 'range' => [], 'leadingWildcard' => [], 'functionWrapped' => []];
        if (preg_match(self::OR_JOINED, $attachedCondition) === 1) {
            return $groups;
        }

        preg_match_all(self::FUNCTION_WRAPPED, $attachedCondition, $functionMatches, PREG_SET_ORDER);
        $wrapped = [];
        foreach ($functionMatches as $match) {
            if ($match['qualifier'] !== $aliasOrTable) {
                continue;
            }

            $wrapped[$match['column']] = true;
            $groups['functionWrapped'][] = ['column' => $match['column'], 'operator' => '', 'literal' => null, 'function' => strtolower($match['function'])];
        }

        preg_match_all(self::PREDICATE, $attachedCondition, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if ($match['qualifier'] !== $aliasOrTable || isset($wrapped[$match['column']])) {
                continue;
            }

            self::classify($groups, $match);
        }

        return $groups;
    }

    /**
     * @param array{equality: list<ConditionColumnMatch>, range: list<ConditionColumnMatch>, leadingWildcard: list<ConditionColumnMatch>, functionWrapped: list<ConditionColumnMatch>} $groups
     * @param array<string, string> $match
     */
    private static function classify(array &$groups, array $match): void
    {
        $operator = strtolower($match['operator']);
        $literal = trim($match['literal']) === '' ? null : trim($match['literal']);
        $entry = ['column' => $match['column'], 'operator' => $operator, 'literal' => $literal, 'function' => null];

        if ($operator === '=' || str_starts_with($operator, 'in')) {
            $groups['equality'][] = $entry;

            return;
        }

        if ($operator === 'like') {
            if ($literal !== null && str_starts_with($literal, "'%")) {
                $groups['leadingWildcard'][] = $entry;
            }

            return;
        }

        if (in_array($operator, ['>', '<', '>=', '<=', 'between'], true)) {
            $groups['range'][] = $entry;
        }
    }
}

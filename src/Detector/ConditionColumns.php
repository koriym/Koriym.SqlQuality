<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use function array_column;
use function array_flip;
use function explode;
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
 * @psalm-type ConditionColumnGroups = array{equality: list<ConditionColumnMatch>, range: list<ConditionColumnMatch>, leadingWildcard: list<ConditionColumnMatch>, functionWrapped: list<ConditionColumnMatch>}
 */
final class ConditionColumns
{
    private const OR_JOINED = '/\)\s*or\s*\(/i';
    private const FUNCTION_WRAPPED = '/(?<function>\w+)\(\s*`\w+`\.`(?<qualifier>\w+)`\.`(?<column>\w+)`/i';
    private const PREDICATE = '/`\w+`\.`(?<qualifier>\w+)`\.`(?<column>\w+)`\s*(?<operator>>=|<=|<>|!=|=|>|<|in\s*\(|between|like|is)\s*(?<literal>[^)]*)/i';
    private const NUMBER = '/^-?\d+(?:\.\d+)?$/';

    /** @return ConditionColumnGroups */
    public static function forAlias(string $attachedCondition, string $aliasOrTable): array
    {
        $groups = ['equality' => [], 'range' => [], 'leadingWildcard' => [], 'functionWrapped' => []];
        if (preg_match(self::OR_JOINED, $attachedCondition) === 1) {
            return $groups;
        }

        $groups['functionWrapped'] = self::functionWrapped($attachedCondition, $aliasOrTable);
        foreach (self::predicates($attachedCondition, $aliasOrTable) as $match) {
            self::classify($groups, $match);
        }

        return $groups;
    }

    /**
     * Predicates comparing a bare column of the alias with nothing but numbers. Unlike forAlias(), branches of a
     * top-level OR are included: the conversion happens whether or not the branch holds on its own.
     *
     * @return list<ConditionColumnMatch>
     */
    public static function comparedToNumber(string $attachedCondition, string $aliasOrTable): array
    {
        $matches = [];
        foreach (self::predicates($attachedCondition, $aliasOrTable) as $match) {
            if (self::isNumber($match)) {
                $matches[] = $match;
            }
        }

        return $matches;
    }

    /** @return list<ConditionColumnMatch> */
    private static function functionWrapped(string $attachedCondition, string $aliasOrTable): array
    {
        preg_match_all(self::FUNCTION_WRAPPED, $attachedCondition, $matches, PREG_SET_ORDER);
        $wrapped = [];
        foreach ($matches as $match) {
            if ($match['qualifier'] !== $aliasOrTable) {
                continue;
            }

            $wrapped[] = ['column' => $match['column'], 'operator' => '', 'literal' => null, 'function' => strtolower($match['function'])];
        }

        return $wrapped;
    }

    /** @return list<ConditionColumnMatch> predicates on a column of the alias that is not wrapped in a function */
    private static function predicates(string $attachedCondition, string $aliasOrTable): array
    {
        $wrapped = array_flip(array_column(self::functionWrapped($attachedCondition, $aliasOrTable), 'column'));
        preg_match_all(self::PREDICATE, $attachedCondition, $matches, PREG_SET_ORDER);
        $predicates = [];
        foreach ($matches as $match) {
            if ($match['qualifier'] !== $aliasOrTable || isset($wrapped[$match['column']])) {
                continue;
            }

            $literal = trim($match['literal']);
            $predicates[] = ['column' => $match['column'], 'operator' => strtolower($match['operator']), 'literal' => $literal === '' ? null : $literal, 'function' => null];
        }

        return $predicates;
    }

    /**
     * @param ConditionColumnGroups $groups
     * @param ConditionColumnMatch  $match
     */
    private static function classify(array &$groups, array $match): void
    {
        $operator = $match['operator'];
        if ($operator === '=' || str_starts_with($operator, 'in')) {
            $groups['equality'][] = $match;

            return;
        }

        if ($operator === 'like') {
            if ($match['literal'] !== null && str_starts_with($match['literal'], "'%")) {
                $groups['leadingWildcard'][] = $match;
            }

            return;
        }

        if (in_array($operator, ['>', '<', '>=', '<=', 'between'], true)) {
            $groups['range'][] = $match;
        }
    }

    /** @param ConditionColumnMatch $match */
    private static function isNumber(array $match): bool
    {
        if ($match['literal'] === null) {
            return false;
        }

        $values = match (true) {
            str_starts_with($match['operator'], 'in') => explode(',', $match['literal']),
            $match['operator'] === 'between' => explode(' and ', $match['literal']),
            default => [$match['literal']],
        };
        foreach ($values as $value) {
            if (preg_match(self::NUMBER, trim($value)) !== 1) {
                return false;
            }
        }

        return true;
    }
}

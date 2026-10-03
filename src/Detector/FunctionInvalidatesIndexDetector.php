<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function implode;
use function in_array;
use function preg_match;
use function sprintf;
use function str_contains;

/**
 * @psalm-import-type ConditionColumnMatch from ConditionColumns
 * @psalm-import-type Suggestion from Types
 */
final class FunctionInvalidatesIndexDetector implements DetectorInterface
{
    private const QUOTED_LITERAL = "/^'[^']*'$/";

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if (! isset($table['attached_condition'])) {
                continue;
            }

            $condition = $table['attached_condition'];
            $reported = [];
            foreach (ConditionColumns::inAnyBranch($condition, $table['table_name'])['functionWrapped'] as $match) {
                if (isset($reported[$match['column']])) {
                    continue;
                }

                $indexes = $this->indexesContaining($context, $table['table_name'], $match['column']);
                if ($indexes === []) {
                    continue;
                }

                $reported[$match['column']] = true;
                $findings[] = new Finding(
                    evidence: [
                        'table_name' => $table['table_name'],
                        'column' => $match['column'],
                        'function' => $match['function'],
                        'indexes' => $indexes,
                        'attached_condition' => $condition,
                    ],
                    suggestion: $this->suggestion($condition, $match, $indexes),
                );
            }
        }

        return $findings;
    }

    /** @return list<string> */
    private function indexesContaining(QueryContext $context, string $aliasOrTable, string $column): array
    {
        $names = [];
        foreach ($context->indexColumns($aliasOrTable) as $name => $columns) {
            if (in_array($column, $columns, true)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @param ConditionColumnMatch $match
     * @param list<string>         $indexes
     *
     * @return Suggestion
     */
    private function suggestion(string $condition, array $match, array $indexes): array
    {
        $column = $match['column'];
        $description = sprintf('%s(%s) is computed on every row, so %s cannot be used to look up %s; compare the column itself and move the computation to the literal side.', (string) $match['function'], $column, implode(', ', $indexes), $column);
        if (! $this->isDateEquality($condition, $match)) {
            return ['kind' => 'rewrite', 'description' => $description];
        }

        return ['kind' => 'rewrite', 'description' => $description, 'sql' => sprintf('%s >= %s AND %s < %s + INTERVAL 1 DAY', $column, (string) $match['literal'], $column, (string) $match['literal'])];
    }

    /** @param ConditionColumnMatch $match */
    private function isDateEquality(string $condition, array $match): bool
    {
        $toDate = $match['function'] === 'date' || ($match['function'] === 'cast' && str_contains($condition, sprintf('`%s` as date)', $match['column'])));

        return $toDate && $match['operator'] === '=' && preg_match(self::QUOTED_LITERAL, (string) $match['literal']) === 1;
    }
}

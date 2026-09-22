<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function in_array;
use function preg_replace;
use function sprintf;
use function str_contains;

/**
 * @psalm-import-type ConditionColumnMatch from ConditionColumns
 * @psalm-import-type ExplainTable from Types
 */
final class ImplicitTypeConversionDetector implements DetectorInterface
{
    /** information_schema DATA_TYPE values that MySQL converts to a number when compared with a numeric literal */
    private const STRING_TYPES = ['char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext', 'enum', 'set'];

    /** MySQL warning "Cannot use ref access on index ... due to type or collation conversion on field ..." */
    private const REF_ACCESS_LOST = 1739;

    private const CONFIDENCE_WITH_WARNING = 0.95;

    private const CONFIDENCE = 0.8;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            $reported = [];
            foreach ([$table['attached_condition'] ?? null, $table['index_condition'] ?? null] as $condition) {
                if ($condition === null) {
                    continue;
                }

                foreach (ConditionColumns::comparedToNumber($condition, $table['table_name']) as $match) {
                    $type = $context->columnType($table['table_name'], $match['column']);
                    if ($type === null || ! in_array($type, self::STRING_TYPES, true) || isset($reported[$match['column']])) {
                        continue;
                    }

                    $reported[$match['column']] = true;
                    $findings[] = $this->finding($context, $table, $match, $type);
                }
            }
        }

        return $findings;
    }

    /**
     * @param ExplainTable         $table
     * @param ConditionColumnMatch $match
     */
    private function finding(QueryContext $context, array $table, array $match, string $type): Finding
    {
        $warning = $this->refAccessWarning($context, $match['column']);
        $quoted = (string) preg_replace('/-?\d+(?:\.\d+)?/', "'\$0'", (string) $match['literal']);

        return new Finding(
            evidence: [
                'table_name' => $table['table_name'],
                'column' => $match['column'],
                'column_type' => $type,
                'operator' => $match['operator'],
                'literal' => $match['literal'],
                'attached_condition' => $table['attached_condition'] ?? null,
                'index_condition' => $table['index_condition'] ?? null,
                'warning' => $warning,
            ],
            confidence: $warning === null ? self::CONFIDENCE : self::CONFIDENCE_WITH_WARNING,
            suggestion: ['kind' => 'rewrite', 'description' => sprintf('%s.%s is %s and is compared with a number, which converts the column on every row and prevents index lookups; quote the literal (%s) or change the column to a numeric type.', $table['table_name'], $match['column'], $type, $quoted)],
        );
    }

    private function refAccessWarning(QueryContext $context, string $column): string|null
    {
        foreach ($context->warningsWithCode(self::REF_ACCESS_LOST) as $warning) {
            if (str_contains($warning['Message'], sprintf("field '%s'", $column))) {
                return $warning['Message'];
            }
        }

        return null;
    }
}

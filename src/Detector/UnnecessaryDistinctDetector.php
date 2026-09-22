<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_diff;
use function array_key_first;
use function count;
use function is_array;
use function preg_match;
use function preg_replace;
use function preg_split;
use function trim;

/** @psalm-import-type ExplainNode from Types */
final class UnnecessaryDistinctDetector implements DetectorInterface
{
    private const SELECT_DISTINCT = '/^SELECT\s+DISTINCT\s+(?<list>.+?)\s+FROM\b/is';
    private const SELECT_ITEM = '/^(?:`?\w+`?\.)?(?:`?(?<column>\w+)`?|(?<star>\*))(?:\s+(?:AS\s+)?`?\w+`?)?$/i';

    #[Override]
    public function detect(QueryContext $context): array
    {
        $aliases = $context->aliases();
        $sql = trim((string) preg_replace('/--.*$/m', '', $context->sql));
        if (count($aliases) !== 1 || preg_match(self::SELECT_DISTINCT, $sql, $match) !== 1 || ! $this->hasDuplicatesRemoval($context->explain['query_block'])) {
            return [];
        }

        $alias = array_key_first($aliases);
        $primaryKey = $context->primaryKeyColumns($alias);
        $selectList = preg_split('/\s*,\s*/', trim($match['list'])) ?: [];
        if ($primaryKey === [] || ! $this->selectsAll($selectList, $primaryKey)) {
            return [];
        }

        return [
            new Finding(
                evidence: ['table_name' => $alias, 'primary_key' => $primaryKey, 'select_list' => $selectList],
                suggestion: [
                    'kind' => 'rewrite',
                    'description' => 'Every row is already unique by its primary key; DISTINCT only adds a duplicate-removal pass.',
                    'sql' => (string) preg_replace('/^SELECT\s+DISTINCT\b/i', 'SELECT', $sql, 1),
                ],
            ),
        ];
    }

    /** @param ExplainNode $node */
    private function hasDuplicatesRemoval(array $node): bool
    {
        foreach ($node as $key => $child) {
            if ($key === 'duplicates_removal' || (is_array($child) && $this->hasDuplicatesRemoval($child))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $selectList
     * @param list<string> $primaryKey
     *
     * @return bool false also when an item is not a plain column or star, such as a function call
     */
    private function selectsAll(array $selectList, array $primaryKey): bool
    {
        $columns = [];
        foreach ($selectList as $item) {
            if (preg_match(self::SELECT_ITEM, $item, $match) !== 1) {
                return false;
            }

            if (($match['star'] ?? '') === '*') {
                return true;
            }

            $columns[] = $match['column'];
        }

        return array_diff($primaryKey, $columns) === [];
    }
}

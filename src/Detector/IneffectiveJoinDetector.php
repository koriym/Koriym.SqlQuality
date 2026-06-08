<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Override;

use function array_map;
use function array_slice;
use function count;
use function implode;
use function in_array;
use function is_array;
use function is_int;
use function is_numeric;

class IneffectiveJoinDetector implements DetectorInterface
{
    #[Override]
    public function detect(array $explainResult): bool
    {
        $walker = new ExplainWalker();
        $joinGroups = [];
        foreach ($walker->tableAccesses($explainResult) as $access) {
            $groupKey = $this->nestedLoopGroupKey($access['path']);
            if ($groupKey === null) {
                continue;
            }

            $joinGroups[$groupKey][] = $access['table'];
        }

        foreach ($joinGroups as $joinAccesses) {
            if (count($joinAccesses) < 2) {
                continue;
            }

            foreach ($joinAccesses as $tableInfo) {
                if ($this->isIneffectiveJoin($tableInfo)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<array-key> $path
     *
     * @psalm-pure
     */
    private function nestedLoopGroupKey(array $path): string|null
    {
        if (count($path) < 3) {
            return null;
        }

        $tableOffset = count($path) - 1;
        $memberOffset = count($path) - 2;
        $nestedLoopOffset = count($path) - 3;
        if (
            $path[$nestedLoopOffset] !== 'nested_loop' ||
            ! is_int($path[$memberOffset]) ||
            $path[$tableOffset] !== 'table'
        ) {
            return null;
        }

        $groupPath = array_slice($path, 0, $nestedLoopOffset + 1);

        return implode("\0", array_map(static fn (int|string $part): string => (string) $part, $groupPath));
    }

    private function isIneffectiveJoin(array $tableInfo): bool
    {
        // 非効率的なJOINの条件をチェック
        $conditions = [
            // 行数が多すぎる場合
            $this->hasHighRowCount($tableInfo),
            // インデックスが適切に使用されていない場合
            $this->hasInappropriateIndexUsage($tableInfo),
            // コストが高すぎる場合
            $this->hasHighCost($tableInfo),
        ];

        return in_array(true, $conditions, true);
    }

    private function hasHighRowCount(array $tableInfo): bool
    {
        $rowsExamined = $this->toFloat($tableInfo['rows_examined_per_scan'] ?? 0);
        $rowsProduced = $this->toFloat($tableInfo['rows_produced_per_join'] ?? 0);

        // 検査する行数と生成される行数に大きな差がある場合
        return $rowsExamined > 1000 || ($rowsExamined > 0 && $rowsProduced / $rowsExamined < 0.1);
    }

    private function hasInappropriateIndexUsage(array $tableInfo): bool
    {
        $accessType = $tableInfo['access_type'] ?? '';

        return $accessType === 'ALL' || $accessType === 'index';
    }

    private function hasHighCost(array $tableInfo): bool
    {
        $costInfo = $tableInfo['cost_info'] ?? [];
        if (! is_array($costInfo)) {
            return false;
        }

        $readCost = $this->toFloat($costInfo['read_cost'] ?? 0);
        $evalCost = $this->toFloat($costInfo['eval_cost'] ?? 0);

        // コストが高すぎる場合
        return $readCost + $evalCost > 1000;
    }

    /** @psalm-pure */
    private function toFloat(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}

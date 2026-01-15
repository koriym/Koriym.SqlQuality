<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Override;

use function in_array;

class IneffectiveJoinDetector implements DetectorInterface
{
    #[Override]
    public function detect(array $explainResult): bool
    {
        return $this->hasIneffectiveJoin($explainResult);
    }

    private function hasIneffectiveJoin(array $explain): bool
    {
        // クエリブロックの取得
        $queryBlock = $explain['query_block'] ?? null;
        if (! $queryBlock) {
            return false;
        }

        // ネストされたループの検査
        if (isset($queryBlock['grouping_operation']['nested_loop'])) {
            $tables = $queryBlock['grouping_operation']['nested_loop'];

            // テーブル間の結合方法を確認
            foreach ($tables as $table) {
                if (isset($table['table'])) {
                    $tableInfo = $table['table'];

                    // 以下のような条件で非効率的なJOINを検出
                    if ($this->isIneffectiveJoin($tableInfo)) {
                        return true;
                    }
                }
            }
        }

        return false;
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
        $rowsExamined = $tableInfo['rows_examined_per_scan'] ?? 0;
        $rowsProduced = $tableInfo['rows_produced_per_join'] ?? 0;

        // 検査する行数と生成される行数に大きな差がある場合
        return $rowsExamined > 1000 || ($rowsProduced / $rowsExamined < 0.1);
    }

    private function hasInappropriateIndexUsage(array $tableInfo): bool
    {
        $accessType = $tableInfo['access_type'] ?? '';

        return $accessType === 'ALL' || $accessType === 'index';
    }

    private function hasHighCost(array $tableInfo): bool
    {
        $costInfo = $tableInfo['cost_info'] ?? [];
        $readCost = $costInfo['read_cost'] ?? 0;
        $evalCost = $costInfo['eval_cost'] ?? 0;

        // コストが高すぎる場合
        return $readCost + $evalCost > 1000;
    }
}

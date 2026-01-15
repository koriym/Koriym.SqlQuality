<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;

use function is_array;

/**
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainNode from Types
 */
final class ExcessiveDerivedTablesDetector implements DetectorInterface
{
    /**
     * 派生テーブルの過剰使用を検出します
     *
     * @param ExplainResult $explainResult
     */
    public function detect(array $explainResult): bool
    {
        // 一時テーブルのカウント
        $tempTableCount = 0;

        // クエリブロックをトラバースして一時テーブルをチェック
        $this->traverseQueryBlock($explainResult, $tempTableCount);

        // 3つ以上の一時テーブルを過剰使用とみなす
        return $tempTableCount >= 3;
    }

    /**
     * クエリブロックを再帰的に探索して一時テーブルをカウントします
     *
     * @param ExplainNode $node           現在のノード
     * @param int         $tempTableCount 一時テーブルのカウンター（参照渡し）
     */
    private function traverseQueryBlock(array $node, int &$tempTableCount): void
    {
        if (isset($node['materialized_from_subquery']['using_temporary_table'])) {
            if ($node['materialized_from_subquery']['using_temporary_table'] === true) {
                $tempTableCount++;
            }
        }

        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->traverseQueryBlock($value, $tempTableCount);
            }
        }
    }
}

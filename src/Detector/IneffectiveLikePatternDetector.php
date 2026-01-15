<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;
use Override;

use function is_array;
use function preg_match;

/**
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type ExplainNode from Types
 */
final class IneffectiveLikePatternDetector implements DetectorInterface
{
    /**
     * 非効率なLIKEパターンを検出します
     *
     * @param ExplainResult $explainResult
     */
    #[Override]
    public function detect(array $explainResult): bool
    {
        // 非効率なLIKEパターンの数をカウント
        $ineffectiveLikeCount = 0;

        // クエリブロックをトラバースしてLIKEパターンをチェック
        $this->traverseQueryBlock($explainResult, $ineffectiveLikeCount);

        // 1つでも非効率なLIKEパターンがあれば真を返す
        return $ineffectiveLikeCount > 0;
    }

    /**
     * クエリブロックを再帰的に探索して非効率なLIKEパターンをカウントします
     *
     * @param ExplainNode $node                 現在のノード
     * @param int         $ineffectiveLikeCount 非効率なLIKEパターンのカウンター（参照渡し）
     */
    private function traverseQueryBlock(array $node, int &$ineffectiveLikeCount): void
    {
        // テーブル情報を含むノードの場合
        if (isset($node['table'])) {
            $table = $node['table'];
            if ($this->isIneffectiveLikePattern($table)) {
                $ineffectiveLikeCount++;
            }
        }

        // 再帰的に探索
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->traverseQueryBlock($value, $ineffectiveLikeCount);
            }
        }
    }

    /**
     * テーブル情報から非効率なLIKEパターンを判定します
     *
     * @param ExplainTable $table テーブル情報
     */
    private function isIneffectiveLikePattern(array $table): bool
    {
        // attached_conditionが存在し、%で囲まれたLIKEパターンを含む
        if (
            isset($table['attached_condition'])
            && preg_match("/like\s+['\"]\%.*\%['\"]/i", $table['attached_condition'])
        ) {
            // フルテーブルスキャンまたはフィルタリング率が低い場合
            return ($table['access_type'] ?? '') === 'ALL'
                || ($table['filtered'] ?? 100) < 25.0;
        }

        return false;
    }
}

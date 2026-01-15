<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Types;
use Override;

use function is_array;
use function is_string;
use function str_contains;
use function substr_count;

/**
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainTable from Types
 * @psalm-import-type ExplainNode from Types
 */
final class IneffectiveRangeScanDetector implements DetectorInterface
{
    /**
     * 非効率的な範囲スキャンを検出します
     *
     * @param ExplainResult $explainResult
     */
    #[Override]
    public function detect(array $explainResult): bool
    {
        $ineffectiveScans = 0;
        $this->traverseQueryBlock($explainResult, $ineffectiveScans);

        return $ineffectiveScans > 0;
    }

    /**
     * クエリブロックを再帰的に探索して非効率的な範囲スキャンをチェックします
     *
     * @param ExplainNode $node             現在のノード
     * @param int         $ineffectiveScans 非効率的なスキャンのカウンター（参照渡し）
     */
    private function traverseQueryBlock(array $node, int &$ineffectiveScans): void
    {
        // テーブルアクセスの検証
        if (isset($node['table'])) {
            if ($this->isIneffectiveTableAccess($node['table'])) {
                $ineffectiveScans++;

                return;
            }
        }

        // 子ノードの再帰的な探索
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->traverseQueryBlock($value, $ineffectiveScans);
            }
        }
    }

    /**
     * テーブルアクセスが非効率的かどうかを判定します
     *
     * @param ExplainTable $table テーブルアクセス情報
     */
    private function isIneffectiveTableAccess(array $table): bool
    {
        // フルテーブルスキャンでIN句を使用している場合
        if (
            isset($table['access_type']) &&
            $table['access_type'] === 'ALL' &&
            isset($table['attached_condition']) &&
            str_contains($table['attached_condition'], ' in (')
        ) {
            return true;
        }

        // 非効率的な範囲スキャン条件のチェック
        if (isset($table['access_type']) && $table['access_type'] === 'range') {
            // 大量の行数を処理する範囲スキャン
            if (isset($table['rows_examined_per_scan']) && $table['rows_examined_per_scan'] > 1000) {
                return true;
            }

            // 複数のインデックス候補がある場合
            if (isset($table['possible_keys']) && is_string($table['possible_keys']) && substr_count($table['possible_keys'], ',') > 0) {
                return true;
            }
        }

        // ORを使用した条件
        if (isset($table['attached_condition']) && str_contains($table['attached_condition'], ' OR ')) {
            return true;
        }

        // インデックスマージが必要な場合
        if (isset($table['access_type']) && $table['access_type'] === 'index_merge') {
            return true;
        }

        // 選択性の低いインデックススキャン
        return isset($table['filtered']) &&
            isset($table['rows_examined_per_scan']) &&
            $table['filtered'] < 20.00 &&
            $table['rows_examined_per_scan'] > 100;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use function is_array;
use function str_contains;

final class IneffectiveRangeScanDetector implements DetectorInterface
{
    /**
     * 非効率的な範囲スキャンを検出します
     *
     * @param array<string, mixed> $explain EXPLAINの結果
     */
    public function detect(array $explain): bool
    {
        $ineffectiveScans = 0;
        $this->traverseQueryBlock($explain, $ineffectiveScans);

        return $ineffectiveScans > 0;
    }

    /**
     * クエリブロックを再帰的に探索して非効率的な範囲スキャンをチェックします
     *
     * @param array<string, mixed> $node 現在のノード
     * @param int $ineffectiveScans 非効率的なスキャンのカウンター（参照渡し）
     */
    private function traverseQueryBlock(array $node, int &$ineffectiveScans): void
    {
        // 範囲スキャンの条件チェック
        if ($this->isIneffectiveRangeScan($node)) {
            $ineffectiveScans++;
        }

        // 再帰的に子ノードを探索
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->traverseQueryBlock($value, $ineffectiveScans);
            }
        }
    }

    /**
     * 非効率的な範囲スキャンの条件をチェックします
     *
     * @param array<string, mixed> $node
     */
    private function isIneffectiveRangeScan(array $node): bool
    {
        // ORを使用した範囲スキャン
        if (isset($node['Extra']) && str_contains($node['Extra'], 'Using OR')) {
            return true;
        }

        // INクエリで大量の値を使用
        if (isset($node['rows']) && $node['rows'] > 1000 && isset($node['type']) && $node['type'] === 'range') {
            return true;
        }

        // Range checked for each record
        if (isset($node['Extra']) && str_contains($node['Extra'], 'Range checked for each record')) {
            return true;
        }

        // 複数のインデックスを使用する範囲スキャン
        if (isset($node['possible_keys']) && isset($node['key']) &&
            $node['type'] === 'range' && count($node['possible_keys']) > 1) {
            return true;
        }

        return false;
    }
}

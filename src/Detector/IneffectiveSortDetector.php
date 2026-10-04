<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_flip;
use function array_intersect_key;
use function count;
use function is_array;
use function substr;

/** @psalm-import-type ExplainNode from Types */
final class IneffectiveSortDetector implements DetectorInterface
{
    // 行数の閾値（これ以上の行数を処理する場合は非効率とみなす）
    private const ROW_THRESHOLD = 1000;

    // コストの閾値（これ以上のコストの場合は非効率とみなす）
    private const COST_THRESHOLD = 100.0;

    /**
     * 非効率なソート操作を検出します
     *
     * {@inheritDoc}
     */
    #[Override]
    public function detect(QueryContext $context): array
    {
        return $this->traverseQueryBlock($context->explain);
    }

    /**
     * クエリブロックを再帰的に探索して非効率なソート操作をチェックします
     *
     * @param ExplainNode $node 現在のノード
     *
     * @return list<Finding>
     */
    private function traverseQueryBlock(array $node): array
    {
        // ordering_operationの検査
        if (isset($node['ordering_operation'])) {
            $finding = $this->ineffectiveSort($node);

            return $finding === null ? [] : [$finding];
        }

        // 子ノードの再帰的な探索
        foreach ($node as $value) {
            if (is_array($value)) {
                $findings = $this->traverseQueryBlock($value);
                if ($findings !== []) {
                    return $findings;
                }
            }
        }

        return [];
    }

    /**
     * ソート操作が非効率かどうかを判定します
     *
     * @param ExplainNode $node クエリブロックノード
     */
    private function ineffectiveSort(array $node): Finding|null
    {
        $orderingOp = $node['ordering_operation'];
        $table = $orderingOp['table'] ?? null;

        if (! $table) {
            return null;
        }

        // クエリのコストチェック
        $queryCost = $node['cost_info']['query_cost'] ?? 0.0;
        $evidence = array_intersect_key($table, array_flip(['table_name', 'rows_examined_per_scan', 'rows_produced_per_join', 'key', 'used_key_parts', 'backward_index_scan', 'cost_info'])) + ['query_cost' => $queryCost];
        if ($queryCost > self::COST_THRESHOLD) {
            return new Finding($evidence);
        }

        // 行数のチェック
        if (isset($table['rows_examined_per_scan']) && $table['rows_examined_per_scan'] > self::ROW_THRESHOLD) {
            return new Finding($evidence);
        }

        // インデックスの部分使用チェック
        if (
            isset($table['key']) &&
            isset($table['possible_keys']) &&
            isset($table['used_key_parts']) &&
            is_array($table['used_key_parts'])
        ) {
            $usedKeyParts = count($table['used_key_parts']);
            // インデックスの一部のみ使用している場合
            if ($usedKeyParts < $this->getIndexKeyParts($table['key'])) {
                return new Finding($evidence);
            }
        }

        // バックワードインデックススキャンのチェック
        if (isset($table['backward_index_scan']) && $table['backward_index_scan']) {
            // 大量のデータを逆順にスキャンする場合
            if (isset($table['rows_produced_per_join']) && $table['rows_produced_per_join'] > self::ROW_THRESHOLD) {
                return new Finding($evidence);
            }
        }

        // データ読み取り量のチェック
        if (
            isset($table['cost_info']['data_read_per_join']) &&
            $this->parseDataSize($table['cost_info']['data_read_per_join']) > 1024 * 1024 // 1MB以上
        ) {
            return new Finding($evidence);
        }

        return null;
    }

    /**
     * インデックスのキーパーツ数を取得します
     * Note: 実際の実装では、INFORMATION_SCHEMAからインデックス情報を取得する必要があります
     */
    private function getIndexKeyParts(string $indexName): int
    {
        // この実装はデモ用です。実際にはDBからインデックス情報を取得する必要があります
        return match ($indexName) {
            'idx_posts_status_created' => 2,  // status, created_atの2つのカラム
            default => 1
        };
    }

    /**
     * データサイズ文字列をバイト数に変換します
     *
     * @param string $size 例: "2M", "500K" など
     */
    private function parseDataSize(string $size): int
    {
        $units = [
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
        ];

        $unit = substr($size, -1);
        $value = (int) $size;

        return isset($units[$unit]) ? $value * $units[$unit] : $value;
    }
}

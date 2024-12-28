<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

/**
 * ツリー構造のデータを視覚的な文字列として表示するクラス
 */
class TreeNode
{
    /**
     * @param string               $text       ノードのテキスト
     * @param array<string,string> $attributes ノードの属性
     * @param array<TreeNode>      $children   子ノード
     */
    public function __construct(
        public readonly string $text,
        public readonly array $attributes = [],
        public readonly array $children = [],
    ) {
    }
}

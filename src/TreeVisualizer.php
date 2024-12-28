<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function count;
use function implode;
use function sprintf;

/**
 * ツリー構造のデータを視覚的な文字列として表示するクラス
 */
final class TreeVisualizer
{
    private const BRANCH = '+- ';
    private const PIPE = '|  ';
    private const SPACE = '   ';

    /**
     * ツリー構造を文字列として生成
     */
    public function toString(TreeNode $root): string
    {
        return $this->nodeToString($root);
    }

    /**
     * 再帰的にノードを文字列化
     *
     * @param bool            $isLast      同階層で最後のノードかどうか
     * @param array<int,bool> $parentPipes 親の階層でパイプを表示するかどうか
     */
    private function nodeToString(
        TreeNode $node,
        int $depth = 0,
        bool $isLast = true,
        array $parentPipes = [],
    ): string {
        $lines = [];

        // インデントを構築
        $indent = '';
        for ($i = 0; $i < $depth; $i++) {
            $indent .= isset($parentPipes[$i]) ? self::PIPE : self::SPACE;
        }

        // ノードのテキストを追加
        $prefix = $depth === 0 ? '' : self::BRANCH;
        $lines[] = $indent . $prefix . $node->text;

        // 属性を追加
        if (! empty($node->attributes)) {
            $attrIndent = $indent . ($depth === 0 ? '' : ($isLast ? self::SPACE : self::PIPE));
            foreach ($node->attributes as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $lines[] = sprintf('%s%-15s %s', $attrIndent, $key, $value);
                }
            }
        }

        // 子ノードを処理
        if (! empty($node->children)) {
            $newParentPipes = $parentPipes;
            if ($depth > 0) {
                $newParentPipes[$depth - 1] = ! $isLast;
            }

            $lastIdx = count($node->children) - 1;
            foreach ($node->children as $idx => $child) {
                $lines[] = $this->nodeToString(
                    $child,
                    $depth + 1,
                    $idx === $lastIdx,
                    $newParentPipes,
                );
            }
        }

        return implode("\n", $lines);
    }
}

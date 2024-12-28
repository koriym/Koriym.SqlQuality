<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function count;
use function implode;
use function sprintf;

final class ExplainTreeVisualizer
{
    private const BRANCH = '+- ';
    private const PIPE = '|  ';
    private const SPACE = '   ';

    public function toString(TreeNode $root): string
    {
        return $this->nodeToString($root);
    }

    private function nodeToString(
        TreeNode $node,
        int $depth = 0,
        bool $isLast = true,
        array $parentPipes = [],
    ): string {
        $lines = [];

        // インデントを構築
        $indent = '';
        if ($depth > 0) {
            for ($i = 0; $i < $depth - 1; $i++) {
                $indent .= isset($parentPipes[$i]) && $parentPipes[$i] ? self::PIPE : self::SPACE;
            }
        }

        // ノードのテキストを追加
        $prefix = $depth === 0 ? '' : self::BRANCH;
        $lines[] = $indent . $prefix . $node->text;

        // 属性を追加
        if (! empty($node->attributes)) {
            $attrIndent = $indent;
            if ($depth > 0) {
                // 子ノードがある場合は次の階層のインデントを考慮
                $attrIndent .= ! $isLast ? self::PIPE : self::SPACE;
            }

            foreach ($node->attributes as $key => $value) {
                if ($value !== '' && $value !== null) {
                    $lines[] = sprintf('%s%-15s %s', $attrIndent, $key, $value);
                }
            }
        }

        // 子ノードを処理
        if (! empty($node->children)) {
            $lastIdx = count($node->children) - 1;

            foreach ($node->children as $idx => $child) {
                $childIsLast = ($idx === $lastIdx);

                // 子ノードのパイプライン状態を設定
                $childPipes = $parentPipes;
                $childPipes[$depth] = ! $childIsLast;

                $lines[] = $this->nodeToString(
                    $child,
                    $depth + 1,
                    $childIsLast,
                    $childPipes,
                );
            }
        }

        return implode("\n", $lines);
    }
}

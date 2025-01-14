<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function implode;
use function str_repeat;

final class ExplainExplainer
{
    public function explain(TreeNode $node): string
    {
        return $this->generateExplanation($node);
    }

    private function generateExplanation(TreeNode $node, int $depth = 0): string
    {
        $lines = [];
        $indent = str_repeat('  ', $depth);

        // ノードの説明を生成
        $explanation = $this->getNodeExplanation($node);
        $lines[] = $indent . $explanation;

        // 属性の説明を追加
        foreach ($node->attributes as $key => $value) {
            $attrExplanation = $this->getAttributeExplanation($key, $value);
            if ($attrExplanation !== null) {
                $lines[] = $indent . $attrExplanation;
            }
        }

        // 子ノードの説明を再帰的に生成
        foreach ($node->children as $child) {
            $lines[] = '';
            $lines[] = $this->generateExplanation($child, $depth + 1);
        }

        return implode("\n", $lines);
    }

    private function getNodeExplanation(TreeNode $node): string
    {
        return match ($node->text) {
            'JOIN' => 'This operation performs a nested loop join between the following tables:',
            'Index lookup' => 'Using an index for efficient table lookup:',
            'Table scan' => 'Performing a full table scan (reading all rows):',
            'Table' => "Accessing table '{$node->attributes['table']}':",
            default => $node->text,
        };
    }

    private function getAttributeExplanation(string $key, string $value): string|null
    {
        return match ($key) {
            'key' => "- Using index '{$value}'",
            'rows' => "- Examining approximately {$value} rows",
            'filtered' => "- Condition filters {$value}% of rows",
            'possible_keys' => $value ? "- Could potentially use indexes: {$value}" : null,
            'table' => null, // Already included in the main explanation
            'condition' => "- With condition: {$value}",
            default => "- {$key}: {$value}",
        };
    }
}

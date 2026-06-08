<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Override;

use function preg_match;

final class ImplicitTypeConversionDetector implements DetectorInterface
{
    /**
     * attached_condition に数値型と文字列型の比較が含まれているかを検出
     *
     * {@inheritDoc}
     */
    #[Override]
    public function detect(array $explainResult): bool
    {
        $walker = new ExplainWalker();
        foreach ($walker->tables($explainResult) as $table) {
            // attached_condition の確認
            if (! isset($table['attached_condition'])) {
                continue;
            }

            // reference_code のような文字列型のカラムに数値を直接比較している場合を検出
            if ($this->containsStringNumericComparison((string) $table['attached_condition'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * 文字列型と数値型の比較が含まれているか確認
     */
    private function containsStringNumericComparison(string $condition): bool
    {
        // reference_code = 12345 のようなパターンを検出
        if (preg_match('/`[^`]+`\s*=\s*\d+/', $condition)) {
            return true;
        }

        return false;
    }
}

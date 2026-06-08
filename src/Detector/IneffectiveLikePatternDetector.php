<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Koriym\SqlQuality\Types;
use Override;

use function preg_match;

/**
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainTable from Types
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
        $walker = new ExplainWalker();
        foreach ($walker->tables($explainResult) as $table) {
            if ($this->isIneffectiveLikePattern($table)) {
                return true;
            }
        }

        return false;
    }

    /**
     * テーブル情報から非効率なLIKEパターンを判定します
     *
     * @param array<string, mixed> $table テーブル情報
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

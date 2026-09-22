<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_flip;
use function array_intersect_key;
use function preg_match;

/** @psalm-import-type ExplainTable from Types */
final class IneffectiveLikePatternDetector implements DetectorInterface
{
    /**
     * 非効率なLIKEパターンを検出します
     */
    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if ($this->isIneffectiveLikePattern($table)) {
                $findings[] = new Finding(array_intersect_key($table, array_flip(['table_name', 'access_type', 'filtered', 'attached_condition'])));
            }
        }

        return $findings;
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
                || (float) ($table['filtered'] ?? 100) < 25.0;
        }

        return false;
    }
}

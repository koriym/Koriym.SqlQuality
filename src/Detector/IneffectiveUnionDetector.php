<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Override;

final class IneffectiveUnionDetector implements DetectorInterface
{
    /**
     * 非効率なUNIONの使用を検出します
     *
     * {@inheritDoc}
     */
    #[Override]
    public function detect(array $explainResult): bool
    {
        // UNIONの結果が一時テーブルを使用し、
        // かつファイルソートが必要な場合を非効率とみなす
        if (isset($explainResult['query_block']['union_result'])) {
            $unionResult = $explainResult['query_block']['union_result'];

            return ($unionResult['using_temporary_table'] ?? false) === true &&
                ($unionResult['using_filesort'] ?? false) === true;
        }

        return false;
    }
}

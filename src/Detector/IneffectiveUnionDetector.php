<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

final class IneffectiveUnionDetector implements DetectorInterface
{
    /**
     * 非効率なUNIONの使用を検出します
     *
     * @param array<string, mixed> $explain EXPLAINの結果
     */
    public function detect(array $explain): bool
    {
        // UNIONの結果が一時テーブルを使用し、
        // かつファイルソートが必要な場合を非効率とみなす
        if (isset($explain['query_block']['union_result'])) {
            $unionResult = $explain['query_block']['union_result'];

            return ($unionResult['using_temporary_table'] ?? false) === true &&
                ($unionResult['using_filesort'] ?? false) === true;
        }

        return false;
    }
}

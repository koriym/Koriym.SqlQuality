<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Override;

use function array_flip;
use function array_intersect_key;

final class IneffectiveUnionDetector implements DetectorInterface
{
    /**
     * 非効率なUNIONの使用を検出します
     *
     * {@inheritDoc}
     */
    #[Override]
    public function detect(QueryContext $context): array
    {
        // UNIONの結果が一時テーブルを使用し、
        // かつファイルソートが必要な場合を非効率とみなす
        if (! isset($context->explain['query_block']['union_result'])) {
            return [];
        }

        $unionResult = $context->explain['query_block']['union_result'];
        if (($unionResult['using_temporary_table'] ?? false) !== true || ($unionResult['using_filesort'] ?? false) !== true) {
            return [];
        }

        return [new Finding(array_intersect_key($unionResult, array_flip(['table_name', 'using_temporary_table', 'using_filesort'])))];
    }
}

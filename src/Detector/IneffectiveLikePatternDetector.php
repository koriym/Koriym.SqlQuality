<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use Override;

use function sprintf;

final class IneffectiveLikePatternDetector implements DetectorInterface
{
    /** Below this filtered ratio a leading-wildcard LIKE is reported even when the table is not fully scanned */
    private const MAX_FILTERED = 25.0;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $findings = [];
        foreach ($context->tables() as $table) {
            if (! isset($table['attached_condition'])) {
                continue;
            }

            $fullScanOrLowFilter = $table['access_type'] === 'ALL' || (float) ($table['filtered'] ?? 100) < self::MAX_FILTERED;
            if (! $fullScanOrLowFilter) {
                continue;
            }

            foreach (ConditionColumns::inAnyBranch($table['attached_condition'], $table['table_name'])['leadingWildcard'] as $match) {
                $pattern = (string) $match['literal'];
                $findings[] = new Finding(
                    evidence: [
                        'table_name' => $table['table_name'],
                        'column' => $match['column'],
                        'pattern' => $pattern,
                        'access_type' => $table['access_type'],
                        'filtered' => $table['filtered'] ?? null,
                    ],
                    suggestion: ['kind' => 'review', 'description' => sprintf('LIKE %s on %s.%s cannot use an index because the pattern starts with a wildcard; consider a FULLTEXT index on %s or a prefix match.', $pattern, $table['table_name'], $match['column'], $match['column'])],
                );
            }
        }

        return $findings;
    }
}

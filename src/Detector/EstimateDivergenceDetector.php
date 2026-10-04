<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainAnalyzeParser;
use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function preg_match;
use function round;

/**
 * @psalm-immutable
 * @psalm-import-type AnalyzeNode from Types
 */
final class EstimateDivergenceDetector implements DetectorInterface
{
    private const MIN_ACTUAL_ROWS = 100.0;
    private const MIN_RATIO = 10.0;
    private const ALIAS_PATTERN = '/\bon (\S+)/';

    #[Override]
    public function detect(QueryContext $context): array
    {
        if ($context->explainAnalyze === null) {
            return [];
        }

        $findings = [];
        foreach ((new ExplainAnalyzeParser())->parse($context->explainAnalyze) as $node) {
            $finding = $this->findingFor($context, $node);
            if ($finding !== null) {
                $findings[] = $finding;
            }
        }

        return $findings;
    }

    /** @param AnalyzeNode $node */
    private function findingFor(QueryContext $context, array $node): Finding|null
    {
        $estimated = $node['estimated_rows'];
        $actual = $node['actual_rows'];
        if ($estimated === null || $actual === null || $actual < self::MIN_ACTUAL_ROWS) {
            return null;
        }

        $larger = $estimated >= $actual ? $estimated : $actual;
        $smaller = $estimated <= $actual ? $estimated : $actual;
        $ratio = $larger / ($smaller > 1.0 ? $smaller : 1.0);
        if ($ratio < self::MIN_RATIO) {
            return null;
        }

        $table = $this->tableFor($context, $node['operation']);

        return new Finding(
            [
                'operation' => $node['operation'],
                'estimated_rows' => $estimated,
                'actual_rows' => $actual,
                'loops' => $node['loops'],
                'ratio' => round($ratio, 1),
                'table' => $table,
            ],
            suggestion: $table === null
                ? ['kind' => 'review', 'description' => "The optimizer's row estimate diverges from the actual row count; refresh statistics for the scanned table."]
                : ['kind' => 'review', 'description' => "The optimizer's row estimate diverges from the actual row count; run ANALYZE TABLE {$table}."],
        );
    }

    private function tableFor(QueryContext $context, string $operation): string|null
    {
        if (preg_match(self::ALIAS_PATTERN, $operation, $match) !== 1) {
            return null;
        }

        return $context->aliases()[$match[1]] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

/**
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type DetectedWarning from Types
 * @psalm-import-type WarningSeverity from Types
 */
final class FakeAnalysisRun
{
    /**
     * @param array<string, AnalysisResult> $results
     * @param array<string, string>         $skipped
     *
     * @return array{results: array<string, AnalysisResult>, skipped: array<string, string>}
     */
    public static function of(array $results, array $skipped = []): array
    {
        return ['results' => $results, 'skipped' => $skipped];
    }

    /**
     * @param list<DetectedWarning> $issues
     *
     * @return AnalysisResult
     */
    public static function result(
        float $cost,
        array $issues = [],
        bool $executed = true,
        float $executionTime = 0.0059,
        float $costPercent = -44.911
    ): array {
        return [
            'mode' => 'wd',
            'executed' => $executed,
            'skipped_reason' => $executed ? null : 'execution skipped: write statement',
            'issues' => $issues,
            'explain_result' => ['query_block' => ['select_id' => 1], 'analyze_result' => []],
            'ai_suggestions' => '',
            'cost' => $cost,
            'execution_time' => $executionTime,
            'optimizer_comparison' => [
                'with_optimizer' => [],
                'without_optimizer' => [],
                'difference' => ['cost_percent' => $costPercent, 'time_percent' => 0.0],
            ],
        ];
    }

    /**
     * @param WarningSeverity $severity
     *
     * @return DetectedWarning
     */
    public static function issue(string $type, string $severity): array
    {
        return [
            'type' => $type,
            'message' => $type . ' detected.',
            'documentation' => 'https://koriym.github.io/Koriym.SqlQuality/issues/' . $type,
            'severity' => $severity,
            'confidence' => 0.95,
            'detector' => 'Koriym\\SqlQuality\\Detector\\' . $type . 'Detector',
            'evidence' => ['table_name' => 'posts'],
            'suggestion' => null,
        ];
    }
}

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
     * @param list<DetectedWarning> $issuesWithoutOptimizer
     *
     * @return AnalysisResult
     */
    public static function result(
        float $cost,
        array $issues = [],
        bool $executed = true,
        float $executionTime = 0.0059,
        float|null $costWithoutOptimizer = null,
        array $issuesWithoutOptimizer = [],
    ): array {
        return [
            'executed' => $executed,
            'skipped_reason' => $executed ? null : 'execution skipped: write statement',
            'issues' => $issues,
            'explain_result' => ['query_block' => ['select_id' => 1]],
            'cost' => $cost,
            'execution_time' => $executed ? $executionTime : null,
            'without_optimizer' => ['cost' => $costWithoutOptimizer ?? $cost, 'issues' => $issuesWithoutOptimizer],
        ];
    }

    /**
     * @param WarningSeverity $severity
     *
     * @return DetectedWarning
     */
    public static function issue(string $type, string $severity, string $table = 'posts'): array
    {
        return [
            'id' => $type . '@' . $table,
            'type' => $type,
            'message' => $type . ' detected.',
            'documentation' => 'https://koriym.github.io/Koriym.SqlQuality/issues/' . $type,
            'severity' => $severity,
            'confidence' => 0.95,
            'evidence' => ['table_name' => $table],
            'suggestion' => null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use stdClass;

use function count;
use function round;

/**
 * @psalm-import-type AnalysisRun from Types
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type JsonReport from Types
 * @psalm-import-type JsonReportQuery from Types
 * @psalm-import-type WarningSeverity from Types
 * @psalm-import-type BaselineDiff from Types
 */
final class JsonReportGenerator
{
    /**
     * @param AnalysisRun       $run
     * @param BaselineDiff|null $baseline
     *
     * @return JsonReport
     */
    public function generate(array $run, array|null $baseline = null): array
    {
        $totalCost = 0.0;
        $totalIssues = 0;
        /** @var array<WarningSeverity, int> $issuesBySeverity */
        $issuesBySeverity = ['Critical' => 0, 'Warning' => 0, 'Info' => 0];
        $queries = [];

        foreach ($run['results'] as $sqlFile => $result) {
            $totalCost += $result['cost'];
            $totalIssues += count($result['issues']);
            foreach ($result['issues'] as $issue) {
                $issuesBySeverity[$issue['severity']]++;
            }

            $queries[$sqlFile] = self::query($result);
        }

        $analyzed = count($run['results']);
        $skipped = count($run['skipped']);

        $report = [
            'summary' => [
                'total_queries' => $analyzed + $skipped,
                'analyzed' => $analyzed,
                'skipped' => $skipped,
                'total_cost' => round($totalCost, 2),
                'avg_cost' => $analyzed > 0 ? round($totalCost / $analyzed, 2) : 0.0,
                'total_issues' => $totalIssues,
                'issues_by_severity' => $issuesBySeverity,
            ],
            'queries' => $queries === [] ? new stdClass() : $queries,
            'skipped' => $run['skipped'] === [] ? new stdClass() : $run['skipped'],
        ];
        if ($baseline !== null) {
            $report['baseline'] = $baseline;
        }

        return $report;
    }

    /**
     * @param AnalysisResult $result
     *
     * @return JsonReportQuery
     */
    public static function query(array $result): array
    {
        return [
            'executed' => $result['executed'],
            'skipped_reason' => $result['skipped_reason'],
            'cost' => $result['cost'],
            'execution_time_ms' => $result['execution_time'] === null ? null : round($result['execution_time'] * 1000, 3),
            'issues' => $result['issues'],
            'optimizer_impact' => [
                'cost_without_optimizer' => $result['without_optimizer']['cost'],
                'cost_reduction_percent' => self::costReductionPercent($result['cost'], $result['without_optimizer']['cost']),
                'hidden_issues' => SqlFileAnalyzer::hiddenIssues($result),
            ],
        ];
    }

    /**
     * How much lower the default plan's cost is than the no-optimizer plan's, in percent; negative when it is higher.
     * round() of a value that rounds to zero can produce float -0.0, which json_encode() renders as "-0"; normalized to 0.0.
     */
    public static function costReductionPercent(float $cost, float $costWithoutOptimizer): float
    {
        if ($costWithoutOptimizer <= 0.0) {
            return 0.0;
        }

        $percent = round(($costWithoutOptimizer - $cost) / $costWithoutOptimizer * 100.0, 2);

        return $percent === -0.0 ? 0.0 : $percent;
    }
}

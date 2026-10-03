<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use stdClass;

use function count;
use function round;

/**
 * @psalm-import-type AnalysisRun from Types
 * @psalm-import-type JsonReport from Types
 * @psalm-import-type JsonReportQuery from Types
 * @psalm-import-type WarningSeverity from Types
 */
final class JsonReportGenerator
{
    /**
     * @param AnalysisRun $run
     *
     * @return JsonReport
     */
    public function generate(array $run): array
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

            $queries[$sqlFile] = [
                'mode' => $result['mode'],
                'executed' => $result['executed'],
                'skipped_reason' => $result['skipped_reason'],
                'cost' => $result['cost'],
                'execution_time_ms' => $result['executed'] ? $result['execution_time'] * 1000 : null,
                'issues' => $result['issues'],
                'optimizer_impact' => [
                    'cost_reduction_percent' => round($result['optimizer_comparison']['difference']['cost_percent'], 2),
                ],
            ];
        }

        $analyzed = count($run['results']);
        $skipped = count($run['skipped']);

        return [
            'summary' => [
                'total_queries' => $analyzed + $skipped,
                'analyzed' => $analyzed,
                'skipped' => $skipped,
                'avg_cost' => $analyzed > 0 ? round($totalCost / $analyzed, 2) : 0.0,
                'total_issues' => $totalIssues,
                'issues_by_severity' => $issuesBySeverity,
            ],
            'queries' => $queries === [] ? new stdClass() : $queries,
            'skipped' => $run['skipped'] === [] ? new stdClass() : $run['skipped'],
        ];
    }
}

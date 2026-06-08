<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\RuntimeException;
use Override;

use function abs;
use function array_column;
use function error_log;
use function file_put_contents;
use function implode;
use function is_dir;
use function mkdir;
use function number_format;
use function pathinfo;
use function sprintf;
use function str_replace;

use const PATHINFO_FILENAME;

/** @psalm-import-type AnalysisResult from Types */
class MarkdownSummaryReportGenerator implements SummaryReportGeneratorInterface
{
    private const SIGNIFICANT_COST_IMPACT = 20.0; // 20% difference in cost

    public function __construct(
        private readonly QueryStatisticsInterface $statistics,
        private readonly QueryLevelClassifierInterface $classifier,
    ) {
    }

    public function saveSummaryReport(string $outputDir, string $fileName = 'summary_report.md'): void
    {
        if (! is_dir($outputDir) && ! @mkdir($outputDir, 0777, true)) {
            throw new RuntimeException("Failed to create directory: {$outputDir}");
        }

        $reportPath = $outputDir . '/' . $fileName;
        $queryResults = $this->statistics->getQueryResults();
        $reportContent = $this->generate($queryResults);

        if (@file_put_contents($reportPath, $reportContent) === false) {
            throw new RuntimeException("Failed to save report to file: {$reportPath}");
        }

        error_log("Report successfully saved to: {$reportPath}");
    }

    /** @param array<string, AnalysisResult> $queryResults */
    #[Override]
    public function generate(array $queryResults): string
    {
        $stats = $this->statistics->calculate($queryResults);

        return $this->formatReport(
            $this->generateMainAnalysis($queryResults, $stats),
            $this->generateSignificantImpact($queryResults),
            $stats
        );
    }

    private function generateMainAnalysis(array $queryResults, array $stats): string
    {
        $rows = [];
        foreach ($queryResults as $filename => $result) {
            $optimizer = $result['optimizer_comparison'] ?? null;
            if (! $optimizer) {
                continue;
            }

            $withOpt = $optimizer['with_optimizer'];
            $baseIssues = $optimizer['without_optimizer']['issues'] ?? [];
            $baseName = pathinfo($filename, PATHINFO_FILENAME);

            $rows[] = sprintf(
                '| %s | %.2f | %.2f | %s | %s | [Details](%s.md) |',
                $filename,
                $result['cost'],
                $withOpt['execution_time'] * 1000,  // Convert to milliseconds
                $this->classifier->classify($result['cost'], $stats['avg_cost'], $stats['std_dev']),
                $this->formatIssues($baseIssues),
                $baseName
            );
        }

        return implode("\n", $rows);
    }

    private function generateSignificantImpact(array $queryResults): string
    {
        $rows = [];
        foreach ($queryResults as $filename => $result) {
            $optimizer = $result['optimizer_comparison'] ?? null;
            if (! $optimizer || abs($optimizer['difference']['cost_percent']) < self::SIGNIFICANT_COST_IMPACT) {
                continue;
            }

            $withOpt = $optimizer['with_optimizer'];
            $withoutOpt = $optimizer['without_optimizer'];
            $baseIssues = $withoutOpt['issues'] ?? [];

            $rows[] = sprintf(
                '| %s | %s | %s | %.1f%% | %s | %s |',
                $filename,
                $this->extractAccessPattern($withoutOpt['explain_result']),
                $this->extractAccessPattern($withOpt['explain_result']),
                $optimizer['difference']['cost_percent'],
                $this->formatIssues($baseIssues),
                $this->analyzePlanChanges($withoutOpt['explain_result'], $withOpt['explain_result'])
            );
        }

        if (empty($rows)) {
            return '*No queries with optimizer impact*';
        }

        return "| SQL File | Base Access | Optimized Access | Cost Impact | Base Issues | Plan Changes |\n"
            . "|:----------|:------------|:----------------|:------------|:------------|:-------------|\n"
            . implode("\n", $rows);
    }

    private function extractAccessPattern(array $explain): string
    {
        // Nested loop joins
        if (isset($explain['query_block']['nested_loop'])) {
            $patterns = [];
            foreach ($explain['query_block']['nested_loop'] as $loop) {
                $table = $loop['table'] ?? [];
                if (! empty($table)) {
                    $patterns[] = $this->formatTableAccess($table);
                }
            }

            return implode(' → ', $patterns);
        }

        // Handle single table with ordering operation
        if (isset($explain['query_block']['ordering_operation']['table'])) {
            return $this->formatTableAccess($explain['query_block']['ordering_operation']['table']);
        }

        // Handle single table direct access
        if (isset($explain['query_block']['table'])) {
            return $this->formatTableAccess($explain['query_block']['table']);
        }

        return 'Unknown';
    }

    private function formatTableAccess(array $table): string
    {
        $parts = [];

        // Access type
        $parts[] = $table['access_type'] ?? 'Unknown';

        // Index information
        if (! empty($table['key']) && $table['key'] !== '<auto_key0>') {
            $parts[] = 'using ' . $table['key'];
        }

        // Rows examined
        if (! empty($table['rows_examined_per_scan'])) {
            $parts[] = $table['rows_examined_per_scan'] . ' rows';
        }

        // Filtered percentage if not 100% or if it's in the optimized version
        if (isset($table['filtered']) && ($table['filtered'] !== 100.00 || isset($table['using_index']))) {
            $parts[] = sprintf('%.1f%%', $table['filtered']);
        }

        return implode(', ', $parts);
    }

    private function analyzePlanChanges(array $withoutOpt, array $withOptResult): string
    {
        $changes = [];

        // Filtering efficiency
        $beforeFiltered = $this->extractFiltered($withoutOpt);
        $afterFiltered = $this->extractFiltered($withOptResult);
        if (abs($afterFiltered - $beforeFiltered) > 1.0) {
            $changes[] = sprintf('Filtering: %.1f%% → %.1f%%', $beforeFiltered, $afterFiltered);
        }

        // Cost breakdown changes
        $beforeCost = $this->extractCostBreakdown($withoutOpt);
        $afterCost = $this->extractCostBreakdown($withOptResult);
        if ($beforeCost && $afterCost) {
            $changes[] = sprintf(
                'Cost(R/E): %.1f/%.1f → %.1f/%.1f',
                $beforeCost['read'],
                $beforeCost['eval'],
                $afterCost['read'],
                $afterCost['eval']
            );
        }

        return empty($changes) ? '-' : implode(', ', $changes);
    }

    private function extractFiltered(array $explain): float
    {
        if (isset($explain['query_block']['table']['filtered'])) {
            return (float) $explain['query_block']['table']['filtered'];
        }

        if (isset($explain['query_block']['ordering_operation']['table']['filtered'])) {
            return (float) $explain['query_block']['ordering_operation']['table']['filtered'];
        }

        return 100.0;
    }

    private function extractCostBreakdown(array $explain): ?array
    {
        $costInfo = null;
        if (isset($explain['query_block']['table']['cost_info'])) {
            $costInfo = $explain['query_block']['table']['cost_info'];
        } elseif (isset($explain['query_block']['ordering_operation']['table']['cost_info'])) {
            $costInfo = $explain['query_block']['ordering_operation']['table']['cost_info'];
        }

        if (! $costInfo) {
            return null;
        }

        return [
            'read' => (float) ($costInfo['read_cost'] ?? 0),
            'eval' => (float) ($costInfo['eval_cost'] ?? 0),
        ];
    }

    private function formatCostImpact(float $impact): string
    {
        if (abs($impact) < self::SIGNIFICANT_COST_IMPACT) {
            return '-';
        }

        return sprintf('%+.1f%%', $impact);
    }

    /** @param array<array<string, mixed>> $issues */
    private function formatIssues(array $issues): string
    {
        return empty($issues) ? '-' : implode(', ', array_column($issues, 'type'));
    }

    private function formatReport(string $mainAnalysis, string $optimizerImpact, array $stats): string
    {
        $output = "# SQL Analysis Summary\n\n"
            . "## Query Analysis\n\n"
            . "| SQL File | Cost | Exec Time (ms) | Level | Issues | Report |\n"
            . "|----------|------|----------------|-------|---------|--------|\n"
            . $mainAnalysis . "\n\n"
            . "## Queries with Optimizer Impact\n\n"
            . $optimizerImpact . "\n\n"
            . "## Statistics\n\n"
            . "- Total queries analyzed: {$stats['total_count']}\n"
            . "- Average query cost: {$this->formatFloat($stats['avg_cost'])}\n"
            . "- Standard deviation: {$this->formatFloat($stats['std_dev'])}\n\n";

        return str_replace('_', '\_', $output);
    }

    private function formatFloat(float $value): string
    {
        return number_format($value, 2);
    }
}

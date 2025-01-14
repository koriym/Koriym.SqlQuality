<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use RuntimeException;

use function array_column;
use function count;
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

/**
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type StatisticsResult from Types
 */
class MarkdownSummaryReportGenerator implements SummaryReportGeneratorInterface
{
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
        error_log('Query results count: ' . count($queryResults));
        $reportContent = $this->generate($queryResults);

        if (@file_put_contents($reportPath, $reportContent) === false) {
            throw new RuntimeException("Failed to save report to file: {$reportPath}");
        }

        error_log("Report successfully saved to: {$reportPath}");
    }

    /** @param array<string, AnalysisResult> $queryResults */
    public function generate(array $queryResults): string
    {
        /** @var StatisticsResult $stats */
        $stats = $this->statistics->calculate($queryResults);
        $rows = [];

        foreach ($queryResults as $filename => $result) {
            /** @var list<string> $issueTypes */
            $issueTypes = array_column($result['issues'], 'type');
            $level = $this->classifier->classify(
                $result['cost'],
                $stats['avg_cost'],
                $stats['std_dev'],
            );

            $escapedFilename = str_replace('_', '\_', $filename);
            $rows[] = sprintf(
                '| %s | %.2f | %s | %s | [Details](%s.md) |',
                $escapedFilename,
                $result['cost'],
                $level,
                implode(', ', $issueTypes) ?: '-',
                pathinfo($filename, PATHINFO_FILENAME),
            );
        }

        return $this->formatReport($rows, $stats);
    }

    /**
     * @param list<string>     $rows
     * @param StatisticsResult $stats
     */
    private function formatReport(array $rows, array $stats): string
    {
        return <<<EOF
# SQL Analysis Summary

## Query Analysis List
| SQL File | Cost | Level | Issues | Report |
|----------|------|-------|---------|---------|
{$this->formatRows($rows)}

## Project Statistics
- Total SQLs analyzed: {$stats['total_count']}
- Average query cost: {$this->formatFloat($stats['avg_cost'])}
- Standard deviation: {$this->formatFloat($stats['std_dev'])}
EOF;
    }

    /** @param list<string> $rows */
    private function formatRows(array $rows): string
    {
        return implode("\n", $rows);
    }

    private function formatFloat(float $value): string
    {
        return number_format($value, 2);
    }
}

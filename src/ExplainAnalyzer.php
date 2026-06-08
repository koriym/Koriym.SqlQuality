<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Detector\ExcessiveDerivedTablesDetector;
use Koriym\SqlQuality\Detector\FunctionInvalidatesIndexDetector;
use Koriym\SqlQuality\Detector\ImplicitTypeConversionDetector;
use Koriym\SqlQuality\Detector\IneffectiveJoinDetector;
use Koriym\SqlQuality\Detector\IneffectiveLikePatternDetector;
use Koriym\SqlQuality\Detector\IneffectiveRangeScanDetector;
use Koriym\SqlQuality\Detector\IneffectiveSortDetector;
use Koriym\SqlQuality\Detector\IneffectiveUnionDetector;
use Koriym\SqlQuality\Detector\LowCardinalityIndexDetector;
use Koriym\SqlQuality\Detector\MultiTableUpdateDetector;
use Koriym\SqlQuality\Detector\UnnecessaryDistinctDetector;
use Koriym\SqlQuality\Exception\LogicException;

use function get_class;
use function is_array;
use function sprintf;
use function str_contains;

/**
 * @psalm-import-type WarningType from Types
 * @psalm-import-type WarningMessages from Types
 * @psalm-import-type WarningPattern from Types
 * @psalm-import-type Warning from Types
 * @psalm-import-type WarningSeverity from Types
 * @psalm-import-type DetectedWarning from Types
 * @psalm-import-type ShowWarning from Types
 * @psalm-import-type ShowWarnings from Types
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type ExplainOperation from Types
 * @psalm-import-type QueryCost from Types
 */
final class ExplainAnalyzer
{
    private const DOC_BASE_URL = 'https://koriym.github.io/Koriym.SqlQuality/issues/';

    public const DEFAULT_MESSAGES = [
        'ExcessiveDerivedTables'    => 'Excessive use of derived tables detected.',
        'FunctionInvalidatesIndex'  => 'Function invalidates index.',
        'FullTableScan'            => 'Full table scan detected.',
        'ImplicitTypeConversion'   => 'Implicit type conversion detected.',
        'IneffectiveJoin'          => 'Ineffective join detected.',
        'IneffectiveLikePattern'   => 'Ineffective LIKE pattern detected.',
        'IneffectiveRangeScan'     => 'Ineffective range scan detected. The range condition covers too many rows.',
        'IneffectiveSort'          => 'Ineffective sort operation detected.',
        'IneffectiveUnion'         => 'Ineffective UNION usage detected; temporary table may be used.',
        'LowCardinalityIndex'      => 'Index on low cardinality column detected; this may cause inefficient scans.',
        'MultiTableUpdate'         => 'Multi-table update detected; this may lead to heavy table locking.',
        'TemporaryTableGrouping'   => 'Temporary table required for grouping.',
        'UnnecessaryDistinct'      => 'Unnecessary DISTINCT detected on already unique columns.',
    ];
    /** @var array<WarningType, Warning> */
    private array $warnings;

    /** @param WarningMessages $messages */
    public function __construct(array $messages = self::DEFAULT_MESSAGES)
    {
        /** @psalm-suppress InvalidPropertyAssignmentValue */
        $this->warnings = [
            'ExcessiveDerivedTables' => [
                'detector' => new ExcessiveDerivedTablesDetector(),
                'message' => $messages['ExcessiveDerivedTables'],
            ],
            'FunctionInvalidatesIndex' => [
                'detector' => new FunctionInvalidatesIndexDetector(),
                'message' => $messages['FunctionInvalidatesIndex'],
            ],
            'FullTableScan' => [
                'message' => $messages['FullTableScan'],
                'pattern' => [
                    'explain' => ['access_type' => 'ALL'],
                ],
            ],
            'ImplicitTypeConversion' => [
                'message' => $messages['ImplicitTypeConversion'],
                'detector' => new ImplicitTypeConversionDetector(),
            ],
            'IneffectiveJoin' => [
                'message' => $messages['IneffectiveJoin'],
                'detector' => new IneffectiveJoinDetector(),
            ],
            'IneffectiveLikePattern' => [
                'message' => $messages['IneffectiveLikePattern'],
                'detector' => new IneffectiveLikePatternDetector(),
            ],
            'IneffectiveRangeScan' => [
                'message' => $messages['IneffectiveRangeScan'],
                'detector' => new IneffectiveRangeScanDetector(),
            ],
            'IneffectiveSort' => [
                'message' => $messages['IneffectiveSort'],
                'detector' => new IneffectiveSortDetector(),
            ],
            'IneffectiveUnion' => [
                'message' => $messages['IneffectiveUnion'],
                'detector' => new IneffectiveUnionDetector(),
            ],
            'LowCardinalityIndex' => [
                'message' => $messages['LowCardinalityIndex'],
                'detector' => new LowCardinalityIndexDetector(),
            ],
            'MultiTableUpdate' => [
                'message' => $messages['MultiTableUpdate'],
                'detector' => new MultiTableUpdateDetector(),
            ],
            'TemporaryTableGrouping' => [
                'message' => $messages['TemporaryTableGrouping'],
                'pattern' => [
                    'explain' => ['using_temporary_table' => true],
                ],
            ],
            'UnnecessaryDistinct' => [
                'message' => $messages['UnnecessaryDistinct'],
                'detector' => new UnnecessaryDistinctDetector(),
            ],
        ];
    }

    /**
     * @param ExplainResult $explainResult
     * @param ShowWarnings  $warnings
     *
     * @return list<DetectedWarning>
     */
    public function analyze(array $explainResult, array $warnings = []): array
    {
        $detectedWarnings = [];
        foreach ($this->warnings as $warningType => $warning) {
            if (isset($warning['detector'])) {
                $detector = $warning['detector'];
                if ($detector->detect($explainResult)) {
                    $detectedWarnings[] = $this->createIssue(
                        $warningType,
                        $warning['message'],
                        ['source' => 'EXPLAIN FORMAT=JSON', 'detector' => get_class($detector)],
                    );
                }

                continue;
            }

            if (isset($warning['pattern'])) {
                if ($this->matchesPattern($explainResult, $warnings, $warning['pattern'])) {
                    $detectedWarnings[] = $this->createIssue(
                        $warningType,
                        $warning['message'],
                        ['source' => 'EXPLAIN FORMAT=JSON / SHOW WARNINGS', 'pattern' => $warning['pattern']],
                    );
                }

                continue;
            }

            throw new LogicException('Invalid warning configuration:' . $warningType);
        }

        return $detectedWarnings;
    }

    /**
     * @param ExplainResult  $explainResult
     * @param ShowWarnings   $warnings
     * @param WarningPattern $pattern
     */
    private function matchesPattern(array $explainResult, array $warnings, array $pattern): bool
    {
        if (isset($pattern['explain'])) {
            foreach ($pattern['explain'] as $key => $value) {
                if (! $this->matchExplainPattern($explainResult, $key, $value)) {
                    return false;
                }
            }
        }

        if (isset($pattern['warnings'])) {
            foreach ($pattern['warnings'] as $warningPattern) {
                if (! $this->matchWarningPattern($warnings, $warningPattern)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param ExplainResult $explainResult */
    private function matchExplainPattern(array $explainResult, string $key, mixed $value): bool
    {
        if (isset($explainResult['query_block']) && is_array($explainResult['query_block'])) {
            $walker = new ExplainWalker();
            if ($walker->contains($explainResult['query_block'], $key, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param ShowWarnings $warnings */
    private function matchWarningPattern(array $warnings, string $pattern): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning['Message'], $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param WarningType          $warningType
     * @param array<string, mixed> $evidence
     *
     * @return DetectedWarning
     */
    private function createIssue(string $warningType, string $message, array $evidence): array
    {
        return [
            'type' => $warningType,
            'message' => $message,
            'documentation' => $this->getDocumentationUrl($warningType),
            'severity' => self::getSeverity($warningType),
            'confidence' => self::getConfidence($warningType),
            'evidence' => $evidence,
        ];
    }

    /**
     * @param WarningType $warningType
     *
     * @return WarningSeverity
     *
     * @psalm-pure
     */
    private static function getSeverity(string $warningType): string
    {
        return match ($warningType) {
            'LowCardinalityIndex', 'UnnecessaryDistinct' => 'Info',
            default => 'Warning',
        };
    }

    /**
     * @param WarningType $warningType
     *
     * @psalm-pure
     */
    private static function getConfidence(string $warningType): float
    {
        return match ($warningType) {
            'LowCardinalityIndex', 'UnnecessaryDistinct' => 0.8,
            default => 0.95,
        };
    }

    /** @param WarningType $warningType */
    private function getDocumentationUrl(string $warningType): string
    {
        return self::DOC_BASE_URL . $warningType;
    }

    /** @param list<DetectedWarning> $warnings */
    public function formatResults(array $warnings): string
    {
        $output = '';
        foreach ($warnings as $warning) {
            $output .= sprintf(
                "%s\nSee %s\n",
                $warning['message'],
                $warning['documentation'],
            );
        }

        return $output;
    }

    /** @return QueryCost */
    public function calculateQueryCost(array $explainResult): array
    {
        // デフォルトのコスト構造
        $cost = [
            'total_cost' => 1.0,
            'details' => [
                'rows_examined' => 0,
                'temporary_tables' => false,
                'filesort' => false,
                'full_scan' => false,
            ],
        ];

        if (! isset($explainResult['query_block'])) {
            return $cost;
        }

        // MySQLのquery_costを優先的に使用
        if (isset($explainResult['query_block']['cost_info']['query_cost'])) {
            $cost['total_cost'] = (float) $explainResult['query_block']['cost_info']['query_cost'];
        }

        // 詳細情報の収集（コスト計算とは独立）
        if (isset($explainResult['query_block']['table']['rows'])) {
            $cost['details']['rows_examined'] = $explainResult['query_block']['table']['rows'];
        }

        // Add cost for temporary tables
        if (
            isset($explainResult['query_block']['grouping_operation']['using_temporary_table'])
            && $explainResult['query_block']['grouping_operation']['using_temporary_table']
        ) {
            $cost['details']['temporary_tables'] = true;
        }

        // Add cost for filesort
        if (
            (isset($explainResult['query_block']['grouping_operation']['using_filesort'])
                && $explainResult['query_block']['grouping_operation']['using_filesort'])
            || (isset($explainResult['query_block']['ordering_operation']['using_filesort'])
                && $explainResult['query_block']['ordering_operation']['using_filesort'])
        ) {
            $cost['details']['filesort'] = true;
        }

        // Add cost for full table scans
        if (
            isset($explainResult['query_block']['table']['access_type'])
            && $explainResult['query_block']['table']['access_type'] === 'ALL'
        ) {
            $cost['details']['full_scan'] = true;
        }

        return $cost;
    }

    public function formatResultsWithCost(array $warnings, array $explainResult): string
    {
        $cost = $this->calculateQueryCost($explainResult);
        $output = sprintf("Query Cost: %.2f\n", $cost['total_cost']);

        if ($cost['details']['full_scan']) {
            $output .= "- Full table scan detected\n";
        }

        if ($cost['details']['temporary_tables']) {
            $output .= "- Using temporary tables\n";
        }

        if ($cost['details']['filesort']) {
            $output .= "- Using filesort\n";
        }

        if ($cost['details']['rows_examined'] > 0) {
            $output .= sprintf("- Examining approximately %d rows\n", $cost['details']['rows_examined']);
        }

        $output .= "\nDetected Issues:\n";
        foreach ($warnings as $warning) {
            $output .= sprintf(
                "%s\nSee %s\n",
                $warning['message'],
                $warning['documentation'],
            );
        }

        return $output;
    }
}

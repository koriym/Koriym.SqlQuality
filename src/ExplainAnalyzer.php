<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Detector\CartesianProductDetector;
use Koriym\SqlQuality\Detector\DeepOffsetDetector;
use Koriym\SqlQuality\Detector\DependentSubqueryDetector;
use Koriym\SqlQuality\Detector\DetectorInterface;
use Koriym\SqlQuality\Detector\ExcessiveDerivedTablesDetector;
use Koriym\SqlQuality\Detector\Finding;
use Koriym\SqlQuality\Detector\FullTableScanDetector;
use Koriym\SqlQuality\Detector\FunctionInvalidatesIndexDetector;
use Koriym\SqlQuality\Detector\ImplicitTypeConversionDetector;
use Koriym\SqlQuality\Detector\IneffectiveJoinDetector;
use Koriym\SqlQuality\Detector\IneffectiveLikePatternDetector;
use Koriym\SqlQuality\Detector\IneffectiveRangeScanDetector;
use Koriym\SqlQuality\Detector\IneffectiveSortDetector;
use Koriym\SqlQuality\Detector\IneffectiveUnionDetector;
use Koriym\SqlQuality\Detector\LowCardinalityIndexDetector;
use Koriym\SqlQuality\Detector\MultiTableUpdateDetector;
use Koriym\SqlQuality\Detector\OrderByRandDetector;
use Koriym\SqlQuality\Detector\TemporaryTableGroupingDetector;
use Koriym\SqlQuality\Detector\UnnecessaryDistinctDetector;

use function sprintf;

/**
 * @psalm-import-type WarningType from Types
 * @psalm-import-type WarningMessages from Types
 * @psalm-import-type Warning from Types
 * @psalm-import-type WarningSeverity from Types
 * @psalm-import-type DetectedWarning from Types
 * @psalm-import-type QueryCost from Types
 */
final class ExplainAnalyzer
{
    private const DOC_BASE_URL = 'https://koriym.github.io/Koriym.SqlQuality/issues/';

    public const DEFAULT_MESSAGES = [
        'CartesianProduct'         => 'Cartesian product detected; the join has no key connecting it to the preceding table.',
        'DeepOffset'               => 'Deep OFFSET detected; MySQL scans and discards offset rows before the page starts.',
        'DependentSubquery'        => 'Dependent subquery detected; it runs once per outer row.',
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
        'OrderByRand'              => 'ORDER BY RAND() detected; it forces a filesort over every matching row.',
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
            'CartesianProduct' => [
                'detector' => new CartesianProductDetector(),
                'message' => $messages['CartesianProduct'],
            ],
            'DeepOffset' => [
                'detector' => new DeepOffsetDetector(),
                'message' => $messages['DeepOffset'],
            ],
            'DependentSubquery' => [
                'detector' => new DependentSubqueryDetector(),
                'message' => $messages['DependentSubquery'],
            ],
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
                'detector' => new FullTableScanDetector(),
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
            'OrderByRand' => [
                'detector' => new OrderByRandDetector(),
                'message' => $messages['OrderByRand'],
            ],
            'TemporaryTableGrouping' => [
                'message' => $messages['TemporaryTableGrouping'],
                'detector' => new TemporaryTableGroupingDetector(),
            ],
            'UnnecessaryDistinct' => [
                'message' => $messages['UnnecessaryDistinct'],
                'detector' => new UnnecessaryDistinctDetector(),
            ],
        ];
    }

    /** @return list<DetectedWarning> */
    public function analyze(QueryContext $context): array
    {
        $detectedWarnings = [];
        foreach ($this->warnings as $warningType => $warning) {
            foreach ($warning['detector']->detect($context) as $finding) {
                $detectedWarnings[] = $this->createIssue($warningType, $warning['message'], $warning['detector'], $finding);
            }
        }

        return $detectedWarnings;
    }

    /**
     * @param WarningType $warningType
     *
     * @return DetectedWarning
     */
    private function createIssue(string $warningType, string $message, DetectorInterface $detector, Finding $finding): array
    {
        return [
            'type' => $warningType,
            'message' => $message,
            'documentation' => $this->getDocumentationUrl($warningType),
            'severity' => $finding->severity ?? self::getSeverity($warningType),
            'confidence' => $finding->confidence ?? self::getConfidence($warningType),
            'detector' => $detector::class,
            'evidence' => $finding->evidence,
            'suggestion' => $finding->suggestion,
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
            'FullTableScan', 'IneffectiveJoin' => 'Critical',
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
            'DeepOffset', 'OrderByRand' => 1.0,
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

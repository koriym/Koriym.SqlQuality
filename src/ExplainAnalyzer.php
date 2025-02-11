<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function is_array;
use function sprintf;
use function str_contains;

/**
 * @psalm-import-type WarningType from Types
 * @psalm-import-type WarningMessages from Types
 * @psalm-import-type WarningPattern from Types
 * @psalm-import-type Warning from Types
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
        'FullTableScan'             => 'Full table scan detected.',
        'IneffectiveJoin'           => 'Ineffective join detected.',
        'FunctionInvalidatesIndex'  => 'Function invalidates index.',
        'IneffectiveLikePattern'    => 'Ineffective LIKE pattern detected.',
        'ImplicitTypeConversion'    => 'Implicit type conversion detected.',
        'IneffectiveSort'           => 'Ineffective sort operation detected.',
        'TemporaryTableGrouping'    => 'Temporary table required for grouping.',
        // 追加のissue
 public function __construct(array $messages = self::DEFAULT_MESSAGES)
 {
     $this->warnings = [
         // ... existing patterns ...
         'IneffectiveRangeScan' => [
             'message' => $messages['IneffectiveRangeScan'],
             'pattern' => [
                 'explain' => ['rows_examined_per_scan' => 'high'],
             ],
         ],
         'ExcessiveDerivedTables' => [
             'message' => $messages['ExcessiveDerivedTables'],
             'pattern' => [
                 'explain' => ['derived_table_count' => 'high'],
             ],
         ],
         // ... add patterns for other new warnings ...
     ];
 }
    ];

    /** @var array<WarningType, Warning> */
    private array $warnings;

    /** @param WarningMessages $messages */
    public function __construct(array $messages = self::DEFAULT_MESSAGES)
    {
        $this->warnings = [
            'FullTableScan' => [
                'message' => $messages['FullTableScan'],
                'pattern' => [
                    'explain' => ['access_type' => 'ALL'],
                ],
            ],
            'IneffectiveJoin' => [
                'message' => $messages['IneffectiveJoin'],
                'pattern' => [
                    'explain' => ['using_join_buffer' => true],
                ],
            ],
            'FunctionInvalidatesIndex' => [
                'message' => $messages['FunctionInvalidatesIndex'],
                'pattern' => [
                    'explain' => ['attached_condition' => 'function_call'],
                ],
            ],
            'IneffectiveLikePattern' => [
                'message' => $messages['IneffectiveLikePattern'],
                'pattern' => [
                    'explain' => ['attached_condition' => 'like_scan'],
                ],
            ],
            'ImplicitTypeConversion' => [
                'message' => $messages['ImplicitTypeConversion'],
                'pattern' => [
                    'warnings' => [
                        'Converting column',
                        'Implicit conversion',
                    ],
                ],
            ],
            'IneffectiveSort' => [
                'message' => $messages['IneffectiveSort'],
                'pattern' => [
                    'explain' => ['using_filesort' => true],
                ],
            ],
            'TemporaryTableGrouping' => [
                'message' => $messages['TemporaryTableGrouping'],
                'pattern' => [
                    'explain' => ['using_temporary_table' => true],
                ],
            ],
        ];
    }

    /** @return list<DetectedWarning> */
    public function analyze(array $explainResult, array $warnings = []): array
    {
        $detectedWarnings = [];

        foreach ($this->warnings as $warningType => $warning) {
            if ($this->matchesPattern($explainResult, $warnings, $warning['pattern'])) {
                $detectedWarnings[] = [
                    'type' => $warningType,
                    'message' => $warning['message'],
                    'documentation' => $this->getDocumentationUrl($warningType),
                ];
            }
        }

        return $detectedWarnings;
    }

    /** @param WarningPattern $pattern */
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

    private function matchExplainPattern(array $explainResult, string $key, mixed $value): bool
    {
        if (isset($explainResult['query_block'])) {
            if ($this->findInArray($explainResult['query_block'], $key, $value)) {
                return true;
            }
        }

        return false;
    }

    private function matchWarningPattern(array $warnings, string $pattern): bool
    {
        foreach ($warnings as $warning) {
            if (str_contains($warning['Message'], $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $array */
    private function findInArray(array $array, string $key, mixed $value): bool
    {
        foreach ($array as $k => $v) {
            if ($k === $key && $v === $value) {
                return true;
            }

            if (is_array($v)) {
                if ($this->findInArray($v, $key, $value)) {
                    return true;
                }
            }
        }

        return false;
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

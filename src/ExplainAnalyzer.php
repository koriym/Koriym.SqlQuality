<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function is_array;
use function sprintf;
use function str_contains;

/**
 * @psalm-type WarningType = 'FullTableScan'|'IneffectiveJoin'|'FunctionInvalidatesIndex'|'IneffectiveLikePattern'|'ImplicitTypeConversion'|'IneffectiveSort'|'TemporaryTableGrouping'
 * @psalm-type WarningMessages = array{
 *   FullTableScan: string,
 *   IneffectiveJoin: string,
 *   FunctionInvalidatesIndex: string,
 *   IneffectiveLikePattern: string,
 *   ImplicitTypeConversion: string,
 *   IneffectiveSort: string,
 *   TemporaryTableGrouping: string
 * }
 * @psalm-type WarningPattern = array{
 *   explain?: array<string, mixed>,
 *   warnings?: list<string>
 * }
 * @psalm-type Warning = array{
 *   message: string,
 *   pattern: WarningPattern
 * }
 * @psalm-type DetectedWarning = array{
 *   type: WarningType,
 *   message: string,
 *   documentation: string
 * }
 * @psalm-import-type ExplainResult from SqlFileAnalyzer
 * @psalm-import-type ShowWarnings from SqlFileAnalyzer
 */
final class ExplainAnalyzer
{
    private const DOC_BASE_URL = 'https://koriym.github.io/Koriym.SqlQuality/issues/';

    public const DEFAULT_MESSAGES = [
        'FullTableScan' => 'Full table scan detected.',
        'IneffectiveJoin' => 'Ineffective join detected.',
        'FunctionInvalidatesIndex' => 'Function invalidates index.',
        'IneffectiveLikePattern' => 'Ineffective LIKE pattern detected.',
        'ImplicitTypeConversion' => 'Implicit type conversion detected.',
        'IneffectiveSort' => 'Ineffective sort operation detected.',
        'TemporaryTableGrouping' => 'Temporary table required for grouping.',
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
        if (isset($explainResult['query_block'])) {
            if ($this->findInArray($explainResult['query_block'], $key, $value)) {
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
                "%s\nSee %s\n\n",
                $warning['message'],
                $warning['documentation'],
            );
        }

        return $output;
    }
}

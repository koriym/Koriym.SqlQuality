<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Detector\CartesianProductDetector;
use Koriym\SqlQuality\Detector\DeepOffsetDetector;
use Koriym\SqlQuality\Detector\DependentSubqueryDetector;
use Koriym\SqlQuality\Detector\DetectorInterface;
use Koriym\SqlQuality\Detector\EstimateDivergenceDetector;
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

use function is_array;
use function is_numeric;
use function is_string;
use function strtr;

/**
 * Runs every detector over a QueryContext.
 *
 * @psalm-import-type WarningType from Types
 * @psalm-import-type WarningSeverity from Types
 * @psalm-import-type DetectedWarning from Types
 */
final class ExplainAnalyzer
{
    private const DOC_BASE_URL = 'https://koriym.github.io/Koriym.SqlQuality/issues/';

    public const MESSAGES = [
        'CartesianProduct'         => 'Cartesian product detected; the join has no key connecting it to the preceding table.',
        'DeepOffset'               => 'Deep OFFSET detected; MySQL scans and discards offset rows before the page starts.',
        'DependentSubquery'        => 'Dependent subquery detected; it runs once per outer row.',
        'EstimateDivergence'       => "The optimizer's row estimate diverges from the actual row count.",
        'ExcessiveDerivedTables'   => 'Excessive use of derived tables detected.',
        'FunctionInvalidatesIndex' => 'A function wrapped around an indexed column prevents the index from being used.',
        'FullTableScan'            => 'Full table scan detected.',
        'ImplicitTypeConversion'   => 'Implicit type conversion detected; the column is compared with a value of another type.',
        'IneffectiveJoin'          => 'Ineffective join detected; the inner table is scanned in full or joined through a join buffer.',
        'IneffectiveLikePattern'   => 'Leading-wildcard LIKE pattern detected; it cannot use a B-tree index.',
        'IneffectiveRangeScan'     => 'Ineffective range scan detected; the range covers many rows the condition then discards.',
        'IneffectiveSort'          => 'Filesort over a large or fully scanned input detected.',
        'IneffectiveUnion'         => 'UNION detected; it deduplicates through a temporary table.',
        'LowCardinalityIndex'      => 'Lookup through an index on a low-cardinality column detected.',
        'MultiTableUpdate'         => 'Multi-table update detected; it may lock many rows across tables.',
        'OrderByRand'              => 'ORDER BY RAND() detected; it forces a filesort over every matching row.',
        'TemporaryTableGrouping'   => 'Grouping needs a temporary table.',
        'UnnecessaryDistinct'      => 'DISTINCT over columns that are already unique detected.',
    ];

    /** @var array<WarningType, DetectorInterface> */
    private readonly array $detectors;

    public function __construct()
    {
        /** @psalm-suppress InvalidPropertyAssignmentValue the keys are exactly the WarningType names */
        $this->detectors = [
            'CartesianProduct' => new CartesianProductDetector(),
            'DeepOffset' => new DeepOffsetDetector(),
            'DependentSubquery' => new DependentSubqueryDetector(),
            'EstimateDivergence' => new EstimateDivergenceDetector(),
            'ExcessiveDerivedTables' => new ExcessiveDerivedTablesDetector(),
            'FunctionInvalidatesIndex' => new FunctionInvalidatesIndexDetector(),
            'FullTableScan' => new FullTableScanDetector(),
            'ImplicitTypeConversion' => new ImplicitTypeConversionDetector(),
            'IneffectiveJoin' => new IneffectiveJoinDetector(),
            'IneffectiveLikePattern' => new IneffectiveLikePatternDetector(),
            'IneffectiveRangeScan' => new IneffectiveRangeScanDetector(),
            'IneffectiveSort' => new IneffectiveSortDetector(),
            'IneffectiveUnion' => new IneffectiveUnionDetector(),
            'LowCardinalityIndex' => new LowCardinalityIndexDetector(),
            'MultiTableUpdate' => new MultiTableUpdateDetector(),
            'OrderByRand' => new OrderByRandDetector(),
            'TemporaryTableGrouping' => new TemporaryTableGroupingDetector(),
            'UnnecessaryDistinct' => new UnnecessaryDistinctDetector(),
        ];
    }

    /** @return list<DetectedWarning> */
    public function analyze(QueryContext $context): array
    {
        $issues = [];
        /** @var array<string, int> $seen */
        $seen = [];
        foreach ($this->detectors as $type => $detector) {
            foreach ($detector->detect($context) as $finding) {
                $id = self::issueId($type, $finding);
                $seen[$id] = ($seen[$id] ?? 0) + 1;
                $issues[] = $this->createIssue($seen[$id] === 1 ? $id : $id . '#' . $seen[$id], $type, $finding);
            }
        }

        return $issues;
    }

    /**
     * The id names the issue type and where it is: "FullTableScan@posts", "FunctionInvalidatesIndex@posts.created_at".
     * It does not carry row counts or costs, so it stays the same across runs while the problem persists.
     * A second finding of the same type at the same place gets "#2", and so on. The separators @ . # and the
     * escape \ are backslash-escaped inside a name, so a table called `a#2` or `a.b` cannot produce the id of
     * another place; ordinary names are unchanged.
     *
     * @param WarningType $type
     */
    private static function issueId(string $type, Finding $finding): string
    {
        $table = $finding->evidence['table_name'] ?? null;
        if (! is_string($table) || $table === '') {
            return $type;
        }

        $column = $finding->evidence['column'] ?? null;
        $place = self::escapeName($table);

        return is_string($column) && $column !== '' ? "{$type}@{$place}." . self::escapeName($column) : "{$type}@{$place}";
    }

    private static function escapeName(string $name): string
    {
        return strtr($name, ['\\' => '\\\\', '@' => '\\@', '.' => '\\.', '#' => '\\#']);
    }

    /**
     * @param WarningType $type
     *
     * @return DetectedWarning
     */
    private function createIssue(string $id, string $type, Finding $finding): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'message' => self::MESSAGES[$type],
            'documentation' => self::DOC_BASE_URL . $type,
            'severity' => $finding->severity ?? self::defaultSeverity($type),
            'confidence' => $finding->confidence ?? self::defaultConfidence($type),
            'evidence' => $finding->evidence,
            'suggestion' => $finding->suggestion,
        ];
    }

    /**
     * @param WarningType $type
     *
     * @return WarningSeverity
     *
     * @psalm-pure
     */
    private static function defaultSeverity(string $type): string
    {
        return match ($type) {
            'FullTableScan', 'IneffectiveJoin' => 'Critical',
            'EstimateDivergence', 'LowCardinalityIndex', 'UnnecessaryDistinct' => 'Info',
            default => 'Warning',
        };
    }

    /**
     * @param WarningType $type
     *
     * @psalm-pure
     */
    private static function defaultConfidence(string $type): float
    {
        return match ($type) {
            'EstimateDivergence', 'LowCardinalityIndex', 'UnnecessaryDistinct' => 0.8,
            'DeepOffset', 'OrderByRand' => 1.0,
            default => 0.95,
        };
    }

    /**
     * The optimizer's query_cost of the top query block. A UNION reports no cost of its own, only per
     * branch under union_result.query_specifications; its cost is the sum of the branches. 1.0 when
     * EXPLAIN reports no cost at all.
     *
     * @param array<array-key, mixed> $explain
     */
    public static function queryCost(array $explain): float
    {
        $block = $explain['query_block'] ?? null;

        return is_array($block) ? self::blockCost($block) ?? 1.0 : 1.0;
    }

    /** @param array<array-key, mixed> $block */
    private static function blockCost(array $block): float|null
    {
        /** @psalm-suppress MixedArrayAccess */
        $cost = $block['cost_info']['query_cost'] ?? null;
        if (is_numeric($cost)) {
            return (float) $cost;
        }

        /** @psalm-suppress MixedArrayAccess */
        $specifications = $block['union_result']['query_specifications'] ?? null;
        if (! is_array($specifications)) {
            return null;
        }

        $total = null;
        /** @var mixed $specification */
        foreach ($specifications as $specification) {
            $branch = is_array($specification) && is_array($specification['query_block'] ?? null) ? self::blockCost($specification['query_block']) : null;
            if ($branch !== null) {
                $total = ($total ?? 0.0) + $branch;
            }
        }

        return $total;
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class FullTableScanDetectorTest extends TestCase
{
    public function testReportsEachTableScannedInFull(): void
    {
        $findings = (new FullTableScanDetector())->detect(Fixture::load('1_full_table_scan.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('posts', $findings[0]->evidence['table_name']);
        $this->assertSame(4956, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertArrayNotHasKey('possible_keys', $findings[0]->evidence);
    }

    public function testReportsNothingWithoutTableAccess(): void
    {
        $this->assertSame([], (new FullTableScanDetector())->detect(Fixture::load('12_select1.sql')));
    }

    public function testExcludesInternalTemporaryTablesFromUnionResults(): void
    {
        // The only ALL-access table in this fixture is the internal <union1,2> temp table.
        $findings = (new FullTableScanDetector())->detect(Fixture::load('23_ineffective_union.sql'));

        $this->assertSame([], $findings);
    }

    public function testExcludesAnAliasedMaterializedDerivedTable(): void
    {
        $this->assertSame([], (new FullTableScanDetector())->detect(Fixture::load('32_materialized_derived.sql')));
    }

    public function testSuggestsAnIndexWhenTheAttachedConditionNamesAnUnindexedColumn(): void
    {
        $findings = (new FullTableScanDetector())->detect(Fixture::load('1_full_table_scan.sql'));

        $suggestion = $findings[0]->suggestion;
        $this->assertNotNull($suggestion);
        $this->assertSame('index', $suggestion['kind']);
        $this->assertSame('CREATE INDEX idx_posts_view_count ON posts (view_count)', $suggestion['ddl']);
    }

    public function testSeverityIsInfoBelowTheLowVolumeThreshold(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(rowsExaminedPerScan: 50));

        $this->assertSame('Info', $findings[0]->severity);
    }

    public function testSeverityIsDefaultAtOrAboveTheLowVolumeThreshold(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(rowsExaminedPerScan: 100));

        $this->assertNull($findings[0]->severity);
    }

    public function testSuggestsReviewWhenAnIndexExistsButIsUnusedAndNoTraceExplainsWhy(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(
            rowsExaminedPerScan: 500,
            possibleKeys: ['idx_orders_status'],
        ));

        $this->assertArrayNotHasKey('optimizer_trace', $findings[0]->evidence);
        $this->assertSame(['kind' => 'review', 'description' => 'An index exists but is not used; check selectivity or statistics.'], $findings[0]->suggestion);
    }

    public function testTraceShowsTheRangeScanWasRejectedOnCost(): void
    {
        $findings = (new FullTableScanDetector())->detect(Fixture::load('9_inefficient_in_query.sql'));

        $this->assertSame([
            'cause' => 'cost',
            'index' => 'idx_posts_status_created',
            'rows' => 2978,
            'cost' => 1043.56,
            'table_scan_rows' => 4952,
            'table_scan_cost' => 505.55,
        ], $findings[0]->evidence['optimizer_trace']);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
        $this->assertStringContainsString('rejected on cost (2978 of 4952 rows, cost 1043.56 against 505.55', $findings[0]->suggestion['description'] ?? '');
    }

    public function testTraceShowsTheIndexMergeUnionLostToTheScan(): void
    {
        $findings = (new FullTableScanDetector())->detect(Fixture::load('8_suboptimal_or_condition.sql'));

        $this->assertSame([
            'cause' => 'index_merge_union',
            'indexes' => ['idx_posts_user_id', 'idx_posts_status_created'],
            'cost' => 2726.53,
            'table_scan_rows' => 4952,
            'table_scan_cost' => 505.55,
        ], $findings[0]->evidence['optimizer_trace']);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind'] ?? null);
        $this->assertStringContainsString('rewrite as UNION', $findings[0]->suggestion['description'] ?? '');
    }

    public function testTraceShowsOnlyJoinKeysWereUsableOnTheLeadingTable(): void
    {
        $findings = (new FullTableScanDetector())->detect(Fixture::load('11_nested_loop.sql'));

        $this->assertSame('users', $findings[0]->evidence['table_name']);
        $this->assertSame(['cause' => 'join_key_only', 'indexes' => ['PRIMARY', 'idx_users_id']], $findings[0]->evidence['optimizer_trace']);
    }

    public function testJoinKeyOnlyScanGetsNoSuggestionWithoutAnIndexableCondition(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(
            rowsExaminedPerScan: 500,
            possibleKeys: ['PRIMARY'],
            trace: [
                'table' => 'orders',
                'considered_access_paths' => [
                    ['access_type' => 'ref', 'index' => 'PRIMARY', 'usable' => false, 'chosen' => false],
                    ['access_type' => 'scan', 'rows_to_scan' => 500, 'cost' => 51.75, 'chosen' => true],
                ],
            ],
        ));

        $this->assertSame(['cause' => 'join_key_only', 'indexes' => ['PRIMARY']], $findings[0]->evidence['optimizer_trace']);
        $this->assertNull($findings[0]->suggestion);
    }

    public function testFallsBackToGenericReviewWhenTheTraceHasNoRecognisedCause(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(
            rowsExaminedPerScan: 500,
            possibleKeys: ['idx_orders_status'],
            trace: [
                'table' => 'orders',
                'considered_access_paths' => [
                    ['access_type' => 'ref', 'index' => 'idx_orders_status', 'rows' => 250, 'cost' => 90.0, 'chosen' => false],
                    ['access_type' => 'scan', 'rows_to_scan' => 500, 'cost' => 51.75, 'chosen' => true],
                ],
            ],
        ));

        $this->assertArrayNotHasKey('optimizer_trace', $findings[0]->evidence);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testSuggestsNothingWithoutAConditionOrAnUnusedIndex(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(rowsExaminedPerScan: 500));

        $this->assertNull($findings[0]->suggestion);
    }

    /**
     * @param list<string>              $possibleKeys
     * @param array<string, mixed>|null $trace        the optimizer trace entry for orders
     */
    private static function context(int $rowsExaminedPerScan, array $possibleKeys = [], array|null $trace = null): QueryContext
    {
        $table = [
            'table_name' => 'orders',
            'access_type' => 'ALL',
            'rows_examined_per_scan' => $rowsExaminedPerScan,
        ];
        if ($possibleKeys !== []) {
            $table['possible_keys'] = $possibleKeys;
        }

        return new QueryContext(
            sql: 'SELECT * FROM orders',
            explain: ['query_block' => ['table' => $table]],
            explainAnalyze: null,
            warnings: [],
            schema: ['orders' => ['indexes' => []]],
            optimizerTrace: $trace === null ? null : ['tables' => [$trace], 'transformations' => [], 'condition_processing' => []],
        );
    }
}

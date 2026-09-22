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

    public function testSuggestsReviewWhenAnIndexExistsButIsUnused(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(
            rowsExaminedPerScan: 500,
            possibleKeys: ['idx_orders_status'],
        ));

        $this->assertSame(['kind' => 'review', 'description' => 'An index exists but is not used; check selectivity or statistics.'], $findings[0]->suggestion);
    }

    public function testSuggestsNothingWithoutAConditionOrAnUnusedIndex(): void
    {
        $findings = (new FullTableScanDetector())->detect(self::context(rowsExaminedPerScan: 500));

        $this->assertNull($findings[0]->suggestion);
    }

    /** @param list<string> $possibleKeys */
    private static function context(int $rowsExaminedPerScan, array $possibleKeys = []): QueryContext
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
        );
    }
}

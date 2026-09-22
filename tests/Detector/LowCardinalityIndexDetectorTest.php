<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class LowCardinalityIndexDetectorTest extends TestCase
{
    public function testReportsALookupOnALowCardinalityColumn(): void
    {
        $findings = (new LowCardinalityIndexDetector())->detect(Fixture::load('21_low_cardinality_index.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('users', $findings[0]->evidence['table_name']);
        $this->assertSame('idx_users_status_created', $findings[0]->evidence['key']);
        $this->assertSame('status', $findings[0]->evidence['column']);
        $this->assertSame(3, $findings[0]->evidence['cardinality']);
        $this->assertSame(1000, $findings[0]->evidence['table_rows']);
        $this->assertSame(800, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
    }

    public function testResolvesAnAliasToItsSchemaTable(): void
    {
        $findings = (new LowCardinalityIndexDetector())->detect(Fixture::load('10_redundant_join.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('u', $findings[0]->evidence['table_name']);
        $this->assertSame(1000, $findings[0]->evidence['table_rows']);
    }

    public function testReportsEveryBranchOfAUnion(): void
    {
        $this->assertCount(2, (new LowCardinalityIndexDetector())->detect(Fixture::load('23_ineffective_union.sql')));
    }

    public function testIgnoresALookupOnASelectiveColumn(): void
    {
        $this->assertSame([], (new LowCardinalityIndexDetector())->detect(self::lookup(2003, cardinality: 4317, tableRows: 9810)));
    }

    public function testIgnoresALookupExaminingFewRows(): void
    {
        $this->assertSame([], (new LowCardinalityIndexDetector())->detect(self::lookup(200, cardinality: 3, tableRows: 1000)));
    }

    public function testIgnoresUnknownCardinality(): void
    {
        $this->assertSame([], (new LowCardinalityIndexDetector())->detect(self::lookup(800, cardinality: null, tableRows: 1000)));
    }

    public function testIgnoresUnknownTableRows(): void
    {
        $this->assertSame([], (new LowCardinalityIndexDetector())->detect(self::lookup(800, cardinality: 3, tableRows: 0)));
    }

    private static function lookup(int $rowsExamined, int|null $cardinality, int $tableRows): QueryContext
    {
        return new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'users', 'access_type' => 'ref', 'key' => 'idx_users_status', 'used_key_parts' => ['status'], 'rows_examined_per_scan' => $rowsExamined, 'filtered' => 100.0]]],
            explainAnalyze: null,
            warnings: [],
            schema: ['users' => ['columns' => [], 'indexes' => [['INDEX_NAME' => 'idx_users_status', 'COLUMN_NAME' => 'status', 'NON_UNIQUE' => 1, 'SEQ_IN_INDEX' => 1, 'CARDINALITY' => $cardinality]], 'status' => ['table_rows' => $tableRows]]],
        );
    }
}

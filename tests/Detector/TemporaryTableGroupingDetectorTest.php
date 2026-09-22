<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class TemporaryTableGroupingDetectorTest extends TestCase
{
    public function testReportsPathOfTheTemporaryTable(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(Fixture::load('7_temporary_table_grouping.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame(['ordering_operation'], $findings[0]->evidence['path']);
    }

    public function testRecordsTheNodeKindAndTheTablesBeneathIt(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(Fixture::load('7_temporary_table_grouping.sql'));

        $this->assertSame('ordering_operation', $findings[0]->evidence['node']);
        $this->assertSame(['orders' => 2000], $findings[0]->evidence['tables']);
        $this->assertSame('review', $findings[0]->suggestion['kind'] ?? null);
        $this->assertNull($findings[0]->severity);
    }

    public function testReportsAGroupingOperationThatUsesATemporaryTable(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(Fixture::load('4_no_index_on_join.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame(['grouping_operation'], $findings[0]->evidence['path']);
        $this->assertSame('grouping_operation', $findings[0]->evidence['node']);
        $this->assertSame(['p' => 2478, 'c' => 2], $findings[0]->evidence['tables']);
    }

    public function testReportsNothingWithoutTemporaryTable(): void
    {
        $this->assertSame([], (new TemporaryTableGroupingDetector())->detect(Fixture::load('1_full_table_scan.sql')));
    }

    public function testIgnoresTheUnionResultTemporaryTable(): void
    {
        $this->assertSame([], (new TemporaryTableGroupingDetector())->detect(Fixture::load('23_ineffective_union.sql')));
    }

    public function testIgnoresAnOrderingWithoutGrouping(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'ordering_operation' => [
                    'using_temporary_table' => true,
                    'using_filesort' => true,
                    'table' => ['table_name' => 'orders', 'access_type' => 'ALL', 'rows_examined_per_scan' => 2000],
                ],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    public function testIgnoresDuplicatesRemoval(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'duplicates_removal' => [
                    'using_temporary_table' => true,
                    'table' => ['table_name' => 'orders', 'access_type' => 'ALL', 'rows_examined_per_scan' => 2000],
                ],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    public function testIgnoresGroupingThatBelongsToASubqueryBeneathTheOrdering(): void
    {
        $findings = (new TemporaryTableGroupingDetector())->detect(self::context([
            'query_block' => [
                'select_id' => 1,
                'ordering_operation' => [
                    'using_temporary_table' => true,
                    'using_filesort' => true,
                    'table' => [
                        'table_name' => 't',
                        'access_type' => 'ALL',
                        'rows_examined_per_scan' => 1000,
                        'materialized_from_subquery' => [
                            'query_block' => [
                                'select_id' => 2,
                                'grouping_operation' => [
                                    'using_filesort' => false,
                                    'table' => ['table_name' => 'orders', 'access_type' => 'index', 'rows_examined_per_scan' => 2000],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    /** @param array<string, mixed> $explain */
    private static function context(array $explain): QueryContext
    {
        return new QueryContext(sql: '', explain: $explain, explainAnalyze: null, warnings: [], schema: []);
    }
}

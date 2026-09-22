<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class DependentSubqueryDetectorTest extends TestCase
{
    public function testReportsEachDependentSubqueryWithOuterRows(): void
    {
        $findings = (new DependentSubqueryDetector())->detect(Fixture::load('10_redundant_join.sql'));

        $this->assertCount(2, $findings);
        $this->assertSame(3, $findings[0]->evidence['select_id']);
        $this->assertSame(['select_list_subqueries', 0], $findings[0]->evidence['path']);
        $this->assertSame(800, $findings[0]->evidence['outer_rows']);
        $this->assertSame(['comments'], $findings[0]->evidence['subquery_tables']);
        $this->assertSame("Field or reference 'test.u.id' of SELECT #3 was resolved in SELECT #1", $findings[0]->evidence['warning']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind']);
        $this->assertSame(2, $findings[1]->evidence['select_id']);
        $this->assertSame(['orders'], $findings[1]->evidence['subquery_tables']);
        $this->assertSame("Field or reference 'test.u.id' of SELECT #2 was resolved in SELECT #1", $findings[1]->evidence['warning']);
    }

    public function testOuterScanOfThousandRowsIsCritical(): void
    {
        $findings = (new DependentSubqueryDetector())->detect(Fixture::load('14_correlated_subquery.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame(1000, $findings[0]->evidence['outer_rows']);
        $this->assertSame('Critical', $findings[0]->severity);
    }

    public function testExistsTurnedIntoSemijoinIsNotDependent(): void
    {
        $this->assertSame([], (new DependentSubqueryDetector())->detect(Fixture::load('11_nested_loop.sql')));
    }

    public function testOuterRowsComeFromTheEnclosingBlockOnly(): void
    {
        $findings = (new DependentSubqueryDetector())->detect(new QueryContext(
            sql: '',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'table' => ['table_name' => 'u', 'access_type' => 'ALL', 'rows_examined_per_scan' => 5000],
                    'select_list_subqueries' => [
                        [
                            'dependent' => false,
                            'query_block' => [
                                'select_id' => 2,
                                'table' => ['table_name' => 'o', 'access_type' => 'ref', 'rows_examined_per_scan' => 30],
                                'attached_subqueries' => [
                                    [
                                        'dependent' => true,
                                        'query_block' => [
                                            'select_id' => 3,
                                            'table' => ['table_name' => 'c', 'access_type' => 'ref', 'rows_examined_per_scan' => 2],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertCount(1, $findings);
        $this->assertSame(['select_list_subqueries', 0, 'query_block', 'attached_subqueries', 0], $findings[0]->evidence['path']);
        $this->assertSame(3, $findings[0]->evidence['select_id']);
        $this->assertSame(30, $findings[0]->evidence['outer_rows']);
        $this->assertSame(['c'], $findings[0]->evidence['subquery_tables']);
        $this->assertNull($findings[0]->evidence['warning']);
        $this->assertNull($findings[0]->severity);
    }
}

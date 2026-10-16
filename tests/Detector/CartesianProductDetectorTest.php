<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class CartesianProductDetectorTest extends TestCase
{
    public function testSecondMemberWithoutJoinKeyIsCriticalWhenRowsExplode(): void
    {
        $findings = (new CartesianProductDetector())->detect(Fixture::load('28_cartesian_product.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('p', $findings[0]->evidence['table_name']);
        $this->assertSame('ref', $findings[0]->evidence['access_type']);
        $this->assertSame(['const'], $findings[0]->evidence['ref']);
        $this->assertSame(2478, $findings[0]->evidence['rows_examined_per_scan']);
        $this->assertSame(1982400, $findings[0]->evidence['rows_produced_per_join']);
        $this->assertSame(['u'], $findings[0]->evidence['preceding_tables']);
        $this->assertNull($findings[0]->evidence['attached_condition']);
        $this->assertSame('Critical', $findings[0]->severity);
        $this->assertSame('review', $findings[0]->suggestion['kind']);
    }

    public function testEqRefJoinKeyIsNotCartesian(): void
    {
        $this->assertSame([], (new CartesianProductDetector())->detect(Fixture::load('20_multi_table_update.sql')));
    }

    public function testConstPropagatedJoinKeyIsNotCartesian(): void
    {
        $this->assertSame([], (new CartesianProductDetector())->detect(Fixture::load('31_const_join.sql')));
    }

    public function testOnlyMembersWithoutARealJoinConditionAreFlagged(): void
    {
        $findings = (new CartesianProductDetector())->detect(new QueryContext(
            sql: '',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'nested_loop' => [
                        ['table' => ['table_name' => 'a', 'access_type' => 'ALL', 'rows_examined_per_scan' => 100]],
                        ['table' => ['table_name' => 'b', 'access_type' => 'ref', 'ref' => ['const'], 'rows_produced_per_join' => 50]],
                        ['table' => ['table_name' => 'c', 'access_type' => 'ref', 'attached_condition' => '(`test`.`c`.`b_id` = `test`.`b`.`id`)']],
                        ['table' => ['table_name' => 'd', 'access_type' => 'ref', 'attached_condition' => "(`test`.`d`.`status` = 'x')"]],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertCount(2, $findings);
        $this->assertSame('b', $findings[0]->evidence['table_name']);
        $this->assertSame(['a'], $findings[0]->evidence['preceding_tables']);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('d', $findings[1]->evidence['table_name']);
        $this->assertSame(['a', 'b', 'c'], $findings[1]->evidence['preceding_tables']);
    }

    public function testIgnoresATableFunction(): void
    {
        $findings = (new CartesianProductDetector())->detect(new QueryContext(
            sql: '',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'nested_loop' => [
                        ['table' => ['table_name' => 'articles', 'access_type' => 'index', 'key' => 'PRIMARY', 'rows_examined_per_scan' => 45345]],
                        ['table' => ['table_name' => 'reducebody', 'access_type' => 'ALL', 'table_function' => 'json_table', 'rows_examined_per_scan' => 2, 'rows_produced_per_join' => 90690]],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame([], $findings);
    }
}

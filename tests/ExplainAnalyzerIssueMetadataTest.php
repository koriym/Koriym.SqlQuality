<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function array_column;
use function array_filter;
use function array_unique;
use function array_values;
use function str_starts_with;

final class ExplainAnalyzerIssueMetadataTest extends TestCase
{
    public function testRepeatedFindingsAtTheSamePlaceGetNumberedIds(): void
    {
        $issues = (new ExplainAnalyzer())->analyze(new QueryContext(
            sql: '',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'nested_loop' => [
                        ['table' => ['table_name' => 'a', 'access_type' => 'ALL', 'rows_examined_per_scan' => 1000]],
                        ['table' => ['table_name' => 'b', 'access_type' => 'ALL', 'rows_examined_per_scan' => 1000, 'attached_condition' => '(`test`.`b`.`a_id` = `test`.`a`.`id`)']],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertSame(['FullTableScan@a', 'FullTableScan@b', 'IneffectiveJoin@b'], array_column($issues, 'id'));
        $this->assertSame(ExplainAnalyzer::MESSAGES['IneffectiveJoin'], array_column($issues, 'message', 'type')['IneffectiveJoin']);
    }

    public function testAnalyzeAddsIdSeverityConfidenceAndEvidence(): void
    {
        $analyzer = new ExplainAnalyzer();

        $issues = $analyzer->analyze(new QueryContext(
            sql: 'SELECT * FROM users',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'table' => [
                        'table_name' => 'users',
                        'access_type' => 'ALL',
                        'rows_examined_per_scan' => 1000,
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $this->assertCount(1, $issues);
        $this->assertSame('FullTableScan', $issues[0]['type']);
        $this->assertSame('Critical', $issues[0]['severity']);
        $this->assertSame(0.95, $issues[0]['confidence']);
        $this->assertSame('FullTableScan@users', $issues[0]['id']);
        $this->assertArrayNotHasKey('detector', $issues[0]);
        $this->assertSame('users', $issues[0]['evidence']['table_name']);
        $this->assertSame(1000, $issues[0]['evidence']['rows_examined_per_scan']);
        $this->assertNull($issues[0]['suggestion']);
    }

    public function testASecondFindingAtTheSamePlaceGetsNumberTwo(): void
    {
        $issues = (new ExplainAnalyzer())->analyze(new QueryContext(
            sql: '',
            explain: [
                'query_block' => [
                    'select_id' => 1,
                    'nested_loop' => [
                        ['table' => ['table_name' => 'a', 'access_type' => 'ALL', 'rows_examined_per_scan' => 1000]],
                        ['table' => ['table_name' => 'a', 'access_type' => 'ALL', 'rows_examined_per_scan' => 1000]],
                    ],
                ],
            ],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $ids = array_column($issues, 'id');
        $this->assertContains('FullTableScan@a', $ids);
        $this->assertContains('FullTableScan@a#2', $ids);
    }

    public function testTheCostOfAUnionIsTheSumOfItsBranches(): void
    {
        $explain = [
            'query_block' => [
                'union_result' => [
                    'query_specifications' => [
                        ['query_block' => ['cost_info' => ['query_cost' => '254.80']]],
                        ['query_block' => ['cost_info' => ['query_cost' => '1447.69']]],
                    ],
                ],
            ],
        ];

        $this->assertEqualsWithDelta(1702.49, ExplainAnalyzer::queryCost($explain), 1e-9);
        $this->assertSame(1.0, ExplainAnalyzer::queryCost(['query_block' => ['select_id' => 1]]));
    }

    public function testANameContainingASeparatorCannotTakeAnotherPlacesId(): void
    {
        $scan = static fn (string $table): array => ['table' => ['table_name' => $table, 'access_type' => 'ALL', 'rows_examined_per_scan' => 1000]];
        $issues = (new ExplainAnalyzer())->analyze(new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'nested_loop' => [$scan('a'), $scan('a'), $scan('a#2'), $scan('a.b')]]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        ));

        $ids = array_values(array_filter(array_column($issues, 'id'), static fn (string $id): bool => str_starts_with($id, 'FullTableScan')));
        $this->assertSame(['FullTableScan@a', 'FullTableScan@a#2', 'FullTableScan@a\#2', 'FullTableScan@a\.b'], $ids);
        $this->assertSame(array_unique(array_column($issues, 'id')), array_column($issues, 'id'));
    }
}

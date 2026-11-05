<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function array_column;

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
}

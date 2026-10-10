<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Detector\FullTableScanDetector;
use Koriym\SqlQuality\Exception\UnknownMessageKey;
use PHPUnit\Framework\TestCase;

use function array_column;

final class ExplainAnalyzerIssueMetadataTest extends TestCase
{
    public function testOmittedMessagesFallBackToDefaults(): void
    {
        $analyzer = new ExplainAnalyzer(['FullTableScan' => 'custom']);

        $issues = $analyzer->analyze(new QueryContext(
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

        $messages = array_column($issues, 'message', 'type');
        $this->assertSame('custom', $messages['FullTableScan']);
        $this->assertSame(ExplainAnalyzer::DEFAULT_MESSAGES['IneffectiveJoin'], $messages['IneffectiveJoin']);
    }

    public function testUnknownMessageKeyIsRejected(): void
    {
        $this->expectException(UnknownMessageKey::class);
        $this->expectExceptionMessage('FullTableScn, Foo');

        new ExplainAnalyzer(['FullTableScan' => 'custom', 'FullTableScn' => 'typo', 'Foo' => 'bar']);
    }

    public function testAnalyzeAddsSeverityConfidenceDetectorAndEvidence(): void
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
        $this->assertSame(FullTableScanDetector::class, $issues[0]['detector']);
        $this->assertSame('users', $issues[0]['evidence']['table_name']);
        $this->assertSame(1000, $issues[0]['evidence']['rows_examined_per_scan']);
        $this->assertNull($issues[0]['suggestion']);
    }
}

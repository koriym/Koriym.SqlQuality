<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

final class ExplainAnalyzerIssueMetadataTest extends TestCase
{
    public function testAnalyzeAddsSeverityConfidenceAndEvidence(): void
    {
        $analyzer = new ExplainAnalyzer();

        $issues = $analyzer->analyze([
            'query_block' => [
                'table' => [
                    'table_name' => 'users',
                    'access_type' => 'ALL',
                    'rows' => 1000,
                ],
            ],
        ]);

        $this->assertSame('FullTableScan', $issues[0]['type']);
        $this->assertSame('Warning', $issues[0]['severity']);
        $this->assertSame(0.95, $issues[0]['confidence']);
        $this->assertSame('EXPLAIN FORMAT=JSON / SHOW WARNINGS', $issues[0]['evidence']['source']);
        $this->assertArrayHasKey('pattern', $issues[0]['evidence']);
    }
}

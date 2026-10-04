<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Detector\MultiTableUpdateDetector;
use PHPUnit\Framework\TestCase;

final class MultiTableUpdateDetectorTest extends TestCase
{
    public function testDetectsMultipleUpdatedTables(): void
    {
        $detector = new MultiTableUpdateDetector();

        $findings = $detector->detect(self::context([
            'query_block' => [
                'nested_loop' => [
                    ['table' => ['update' => true, 'table_name' => 'comments', 'access_type' => 'ALL']],
                    ['table' => ['update' => true, 'table_name' => 'posts', 'access_type' => 'eq_ref']],
                ],
            ],
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame(['comments', 'posts'], $findings[0]->evidence['tables']);
    }

    public function testDoesNotDetectSingleTableUpdate(): void
    {
        $detector = new MultiTableUpdateDetector();

        $findings = $detector->detect(self::context([
            'query_block' => [
                'table' => ['update' => true, 'table_name' => 'posts', 'access_type' => 'ALL'],
            ],
        ]));

        $this->assertSame([], $findings);
    }

    public function testDetectsLegacyUpdateOperationMarker(): void
    {
        $detector = new MultiTableUpdateDetector();

        $findings = $detector->detect(self::context([
            'query_block' => ['update_operation' => 'multi_table'],
        ]));

        $this->assertCount(1, $findings);
        $this->assertSame('multi_table', $findings[0]->evidence['update_operation']);
    }

    /** @param array<string, mixed> $explain */
    private static function context(array $explain): QueryContext
    {
        return new QueryContext(sql: '', explain: $explain, explainAnalyze: null, warnings: [], schema: []);
    }
}

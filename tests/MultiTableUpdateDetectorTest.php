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

        $this->assertTrue($detector->detect([
            'query_block' => [
                'nested_loop' => [
                    ['table' => ['update' => true, 'table_name' => 'comments', 'access_type' => 'ALL']],
                    ['table' => ['update' => true, 'table_name' => 'posts', 'access_type' => 'eq_ref']],
                ],
            ],
        ]));
    }

    public function testDoesNotDetectSingleTableUpdate(): void
    {
        $detector = new MultiTableUpdateDetector();

        $this->assertFalse($detector->detect([
            'query_block' => [
                'table' => ['update' => true, 'table_name' => 'posts', 'access_type' => 'ALL'],
            ],
        ]));
    }

    public function testDetectsLegacyUpdateOperationMarker(): void
    {
        $detector = new MultiTableUpdateDetector();

        $this->assertTrue($detector->detect([
            'query_block' => ['update_operation' => 'multi_table'],
        ]));
    }
}

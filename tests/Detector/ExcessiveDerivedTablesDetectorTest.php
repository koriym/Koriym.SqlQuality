<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class ExcessiveDerivedTablesDetectorTest extends TestCase
{
    public function testReportsThreeOrMoreMaterializedDerivedTables(): void
    {
        $findings = (new ExcessiveDerivedTablesDetector())->detect(self::derivedTables(['<derived2>' => 'orders', '<derived3>' => 'users', '<derived4>' => 'posts']));

        $this->assertCount(1, $findings);
        $this->assertSame(3, $findings[0]->evidence['derived_count']);
        $this->assertSame(
            ['<derived2>' => ['orders'], '<derived3>' => ['users'], '<derived4>' => ['posts']],
            $findings[0]->evidence['tables'],
        );
    }

    public function testIgnoresFewerThanThreeMaterializedDerivedTables(): void
    {
        $findings = (new ExcessiveDerivedTablesDetector())->detect(self::derivedTables(['<derived2>' => 'orders', '<derived3>' => 'users']));

        $this->assertSame([], $findings);
    }

    public function testIgnoresDerivedTablesMergedByTheOptimizer(): void
    {
        $this->assertSame([], (new ExcessiveDerivedTablesDetector())->detect(Fixture::load('22_excessive_derived_tables.sql')));
    }

    /** @param array<string, string> $derivedNameToSourceTable */
    private static function derivedTables(array $derivedNameToSourceTable): QueryContext
    {
        $nestedLoop = [];
        $selectId = 2;
        foreach ($derivedNameToSourceTable as $derivedName => $sourceTable) {
            $nestedLoop[] = [
                'table' => [
                    'table_name' => $derivedName,
                    'access_type' => 'ALL',
                    'materialized_from_subquery' => [
                        'using_temporary_table' => true,
                        'dependent' => false,
                        'cacheable' => true,
                        'query_block' => [
                            'select_id' => $selectId++,
                            'table' => ['table_name' => $sourceTable, 'access_type' => 'ALL'],
                        ],
                    ],
                ],
            ];
        }

        return new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'nested_loop' => $nestedLoop]],
            explainAnalyze: null,
            warnings: [],
            schema: [],
        );
    }
}

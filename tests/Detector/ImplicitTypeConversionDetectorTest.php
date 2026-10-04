<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\Fixture;
use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class ImplicitTypeConversionDetectorTest extends TestCase
{
    private const REF_ACCESS_WARNING = "Cannot use ref access on index 'idx_orders_reference_code' due to type or collation conversion on field 'reference_code'";

    public function testReportsAStringColumnComparedWithANumber(): void
    {
        $findings = (new ImplicitTypeConversionDetector())->detect(Fixture::load('6_implicit_type_conversion.sql'));

        $this->assertCount(1, $findings);
        $this->assertSame('orders', $findings[0]->evidence['table_name']);
        $this->assertSame('reference_code', $findings[0]->evidence['column']);
        $this->assertSame('varchar', $findings[0]->evidence['column_type']);
        $this->assertSame('12345', $findings[0]->evidence['literal']);
        $this->assertSame('(`test`.`orders`.`reference_code` = 12345)', $findings[0]->evidence['attached_condition']);
        $this->assertNull($findings[0]->evidence['warning']);
        $this->assertSame(0.8, $findings[0]->confidence);
        $this->assertNull($findings[0]->severity);
        $this->assertSame('rewrite', $findings[0]->suggestion['kind'] ?? null);
        $this->assertStringContainsString("'12345'", $findings[0]->suggestion['description'] ?? '');
    }

    public function testIgnoresANumericColumnComparedWithANumber(): void
    {
        $this->assertSame([], (new ImplicitTypeConversionDetector())->detect(Fixture::load('8_suboptimal_or_condition.sql')));
    }

    public function testRaisesConfidenceWhenMysqlReportsTheLostRefAccess(): void
    {
        $context = self::comparison('(`test`.`orders`.`reference_code` = 12345)', warnings: [['Level' => 'Warning', 'Code' => 1739, 'Message' => self::REF_ACCESS_WARNING]]);

        $findings = (new ImplicitTypeConversionDetector())->detect($context);

        $this->assertCount(1, $findings);
        $this->assertSame(0.95, $findings[0]->confidence);
        $this->assertSame(self::REF_ACCESS_WARNING, $findings[0]->evidence['warning']);
    }

    public function testReportsAnInListOfNumbersButNotAnEnumBetween(): void
    {
        $findings = (new ImplicitTypeConversionDetector())->detect(self::comparison('((`test`.`orders`.`reference_code` in (1,2)) and (`test`.`orders`.`status` between 1 and 9))'));

        $this->assertCount(1, $findings);
        $this->assertSame('reference_code', $findings[0]->evidence['column']);
        $this->assertStringContainsString("('1','2')", $findings[0]->suggestion['description'] ?? '');
    }

    public function testIgnoresAnEnumColumnComparedWithANumber(): void
    {
        $this->assertSame([], (new ImplicitTypeConversionDetector())->detect(self::comparison('(`test`.`orders`.`status` = 1)')));
    }

    public function testReportsEachBranchOfAnOr(): void
    {
        $findings = (new ImplicitTypeConversionDetector())->detect(self::comparison("((`test`.`orders`.`reference_code` = 1) or (`test`.`orders`.`status` = 'paid'))"));

        $this->assertCount(1, $findings);
        $this->assertSame('reference_code', $findings[0]->evidence['column']);
    }

    public function testScansTheIndexCondition(): void
    {
        $findings = (new ImplicitTypeConversionDetector())->detect(self::comparison('(`test`.`orders`.`reference_code` > 100)', field: 'index_condition'));

        $this->assertCount(1, $findings);
        $this->assertSame('(`test`.`orders`.`reference_code` > 100)', $findings[0]->evidence['index_condition']);
        $this->assertNull($findings[0]->evidence['attached_condition']);
    }

    public function testIgnoresAQuotedLiteral(): void
    {
        $this->assertSame([], (new ImplicitTypeConversionDetector())->detect(self::comparison("(`test`.`orders`.`reference_code` = '12345')")));
    }

    public function testIgnoresAColumnMissingFromTheSchema(): void
    {
        $this->assertSame([], (new ImplicitTypeConversionDetector())->detect(self::comparison('(`test`.`orders`.`legacy_code` = 12345)')));
    }

    /** @param list<array{Level: string, Code: int, Message: string}> $warnings */
    private static function comparison(string $condition, array $warnings = [], string $field = 'attached_condition'): QueryContext
    {
        return new QueryContext(
            sql: '',
            explain: ['query_block' => ['select_id' => 1, 'table' => ['table_name' => 'orders', 'access_type' => 'ALL', $field => $condition, 'rows_examined_per_scan' => 2000, 'filtered' => 10.0]]],
            explainAnalyze: null,
            warnings: $warnings,
            schema: ['orders' => ['columns' => [['COLUMN_NAME' => 'reference_code', 'DATA_TYPE' => 'varchar'], ['COLUMN_NAME' => 'status', 'DATA_TYPE' => 'enum']], 'indexes' => [], 'status' => ['table_rows' => 2000]]],
        );
    }
}

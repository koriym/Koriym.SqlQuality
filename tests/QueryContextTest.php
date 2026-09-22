<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;

final class QueryContextTest extends TestCase
{
    public function testTablesListsEveryTableAccessInPlanOrder(): void
    {
        $context = Fixture::load('4_no_index_on_join.sql');

        $this->assertSame(['p', 'c'], array_column($context->tables(), 'table_name'));
        $this->assertSame(['grouping_operation', 'nested_loop', 0, 'table'], $context->tableAccesses()[0]['path']);
    }

    public function testWarningsWithCodeFiltersByCode(): void
    {
        $this->assertCount(1, Fixture::load('20_multi_table_update.sql')->warningsWithCode(1003));
        $this->assertSame([], Fixture::load('20_multi_table_update.sql')->warningsWithCode(1265));
        $this->assertSame([], Fixture::load('1_full_table_scan.sql')->warningsWithCode(1003));
    }

    public function testIndexColumnsOrdersColumnsBySeqInIndex(): void
    {
        $context = Fixture::load('4_no_index_on_join.sql');

        $this->assertSame([
            'idx_posts_status_created' => ['status', 'created_at'],
            'idx_posts_user_id' => ['user_id'],
            'idx_posts_user_status' => ['user_id', 'status'],
            'PRIMARY' => ['id'],
        ], $context->indexColumns('posts'));
        $this->assertSame(['id'], $context->indexColumns('c')['PRIMARY']);
        $this->assertSame([], $context->indexColumns('unknown'));
    }

    public function testAliasesMapAliasAndBareTableName(): void
    {
        $this->assertSame(['p' => 'posts', 'c' => 'comments'], Fixture::load('4_no_index_on_join.sql')->aliases());
        $this->assertSame(['posts' => 'posts'], Fixture::load('1_full_table_scan.sql')->aliases());
        $this->assertSame(['p' => 'posts', 'c' => 'comments'], Fixture::load('20_multi_table_update.sql')->aliases());
    }

    public function testAliasesExcludeDerivedTables(): void
    {
        $aliases = Fixture::load('22_excessive_derived_tables.sql')->aliases();

        $this->assertSame(['orders' => 'orders', 'users' => 'users', 'posts' => 'posts'], $aliases);
        $this->assertArrayNotHasKey('o', $aliases);
        $this->assertArrayNotHasKey('u', $aliases);
        $this->assertArrayNotHasKey('p', $aliases);
    }

    public function testSchemaForResolvesAliasOrTableName(): void
    {
        $context = Fixture::load('4_no_index_on_join.sql');

        $this->assertSame($context->schema['posts'], $context->schemaFor('p'));
        $this->assertSame($context->schema['comments'], $context->schemaFor('comments'));
        $this->assertNull($context->schemaFor('unknown'));
    }

    public function testColumnTypeIsLowercaseDataType(): void
    {
        $context = Fixture::load('4_no_index_on_join.sql');

        $this->assertSame('varchar', $context->columnType('p', 'status'));
        $this->assertSame('int', $context->columnType('posts', 'user_id'));
        $this->assertNull($context->columnType('p', 'missing'));
        $this->assertNull($context->columnType('unknown', 'id'));
    }

    public function testPrimaryKeyColumns(): void
    {
        $context = Fixture::load('4_no_index_on_join.sql');

        $this->assertSame(['id'], $context->primaryKeyColumns('c'));
        $this->assertSame([], $context->primaryKeyColumns('unknown'));
    }

    public function testToArrayRoundTripsThroughFromArray(): void
    {
        $context = Fixture::load('20_multi_table_update.sql');
        $data = $context->toArray();

        $this->assertSame(['sql', 'explain', 'explain_analyze', 'warnings', 'schema'], array_keys($data));
        $this->assertNull($data['explain_analyze']);
        $this->assertEquals($context, QueryContext::fromArray($data));
    }
}

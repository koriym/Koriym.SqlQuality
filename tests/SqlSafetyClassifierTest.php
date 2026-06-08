<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

final class SqlSafetyClassifierTest extends TestCase
{
    private SqlSafetyClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SqlSafetyClassifier();
    }

    public function testSelectIsExplainableAndExecutable(): void
    {
        $result = $this->classifier->classify('SELECT * FROM users WHERE id = :id');

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertTrue($result['is_read_only_select']);
    }

    public function testSelectWithTrailingSemicolonIsExecutable(): void
    {
        $result = $this->classifier->classify('SELECT * FROM users WHERE id = :id;');

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertTrue($result['is_read_only_select']);
    }

    public function testSelectWithStackedWriteStatementIsUnsafe(): void
    {
        $result = $this->classifier->classify('SELECT 1; UPDATE users SET status = "banned"');

        $this->assertSame('unsafe', $result['kind']);
        $this->assertFalse($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testSelectWithQuotedLineCommentMarkerAndStackedWriteIsUnsafe(): void
    {
        $result = $this->classifier->classify("SELECT '--'; UPDATE users SET status = 'banned'");

        $this->assertSame('unsafe', $result['kind']);
        $this->assertFalse($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testDoubleDashWithoutWhitespaceDoesNotHideStackedWrite(): void
    {
        $result = $this->classifier->classify('SELECT 1--1; UPDATE users SET status = "banned"');

        $this->assertSame('unsafe', $result['kind']);
        $this->assertFalse($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testSemicolonInsideStringLiteralDoesNotMakeSelectUnsafe(): void
    {
        $result = $this->classifier->classify("SELECT ';' AS semicolon;");

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertTrue($result['is_read_only_select']);
    }

    public function testSemicolonInsideTrailingCommentDoesNotMakeSelectUnsafe(): void
    {
        $result = $this->classifier->classify("SELECT 1; -- UPDATE users SET status = 'banned'\n");

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertTrue($result['is_read_only_select']);
    }

    public function testWithSelectIsExplainableAndExecutable(): void
    {
        $result = $this->classifier->classify('WITH active_users AS (SELECT * FROM users) SELECT * FROM active_users');

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertTrue($result['is_read_only_select']);
    }

    public function testDmlIsExplainableButNotExecutable(): void
    {
        $result = $this->classifier->classify('UPDATE posts p JOIN comments c ON p.id = c.post_id SET p.view_count = p.view_count + 1');

        $this->assertSame('write', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testUnsafeSelectIsExplainableButNotExecutable(): void
    {
        $result = $this->classifier->classify('SELECT * FROM users WHERE id = 1 FOR UPDATE');

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);

        $forShareResult = $this->classifier->classify('SELECT * FROM users WHERE id = 1 FOR SHARE');

        $this->assertSame('select', $forShareResult['kind']);
        $this->assertTrue($forShareResult['is_explainable']);
        $this->assertFalse($forShareResult['is_read_only_select']);
    }

    public function testDdlIsNotExplainableOrExecutable(): void
    {
        $result = $this->classifier->classify('CREATE TABLE users (id INT PRIMARY KEY)');

        $this->assertSame('ddl', $result['kind']);
        $this->assertFalse($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testSelectWritingToOutfileIsExplainableButNotExecutable(): void
    {
        $result = $this->classifier->classify("SELECT * FROM users INTO OUTFILE '/tmp/users.txt'");

        $this->assertSame('select', $result['kind']);
        $this->assertTrue($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testSelectWithLockingOrBlockingConstructIsExplainableButNotExecutable(): void
    {
        $unsafeSelects = [
            "SELECT GET_LOCK('report', 10)",
            'SELECT SLEEP(5)',
            'SELECT * FROM users WHERE id = 1 LOCK IN SHARE MODE',
        ];

        foreach ($unsafeSelects as $sql) {
            $result = $this->classifier->classify($sql);

            $this->assertSame('select', $result['kind'], $sql);
            $this->assertTrue($result['is_explainable'], $sql);
            $this->assertFalse($result['is_read_only_select'], $sql);
        }
    }

    public function testCallStatementIsUnsafeAndNotExplainable(): void
    {
        $result = $this->classifier->classify('CALL recalc_totals()');

        $this->assertSame('unsafe', $result['kind']);
        $this->assertFalse($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }

    public function testSetStatementIsUnsafeAndNotExplainable(): void
    {
        $result = $this->classifier->classify('SET @counter = 0');

        $this->assertSame('unsafe', $result['kind']);
        $this->assertFalse($result['is_explainable']);
        $this->assertFalse($result['is_read_only_select']);
    }
}

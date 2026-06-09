<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

class SqlClassifierTest extends TestCase
{
    private SqlClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new SqlClassifier();
    }

    /** @return array<string, array{string, string, bool, bool}> */
    public function classificationProvider(): array
    {
        return [
            // sql, expected category, executable, explainable
            'simple select' => ['SELECT * FROM users', 'read_only_select', true, true],
            'select with leading comment' => ["-- a comment\nSELECT 1", 'read_only_select', true, true],
            'with select cte' => ['WITH active AS (SELECT id FROM users WHERE status = \'active\') SELECT * FROM active', 'read_only_select', true, true],
            'parenthesised union select' => ['(SELECT 1) UNION (SELECT 2)', 'read_only_select', true, true],
            'string literal mentioning for update is safe' => ["SELECT * FROM posts WHERE note = 'please FOR UPDATE now'", 'read_only_select', true, true],
            'comment mentioning update is safe' => ["-- UPDATE posts later\nSELECT id FROM posts", 'read_only_select', true, true],

            'update' => ['UPDATE posts SET view_count = view_count + 1 WHERE id = 1', 'dml', false, true],
            'multi table update' => ['UPDATE posts p JOIN comments c ON p.id = c.post_id SET p.view_count = 1', 'dml', false, true],
            'delete' => ['DELETE FROM posts WHERE id = 1', 'dml', false, true],
            'insert' => ['INSERT INTO posts (id) VALUES (1)', 'dml', false, true],
            'replace' => ['REPLACE INTO posts (id) VALUES (1)', 'dml', false, true],
            'with update cte' => ['WITH c AS (SELECT id FROM posts) UPDATE posts SET view_count = 0 WHERE id IN (SELECT id FROM c)', 'dml', false, true],

            'create table' => ['CREATE TABLE t (id INT PRIMARY KEY)', 'ddl', false, false],
            'drop table' => ['DROP TABLE posts', 'ddl', false, false],
            'alter table' => ['ALTER TABLE posts ADD COLUMN x INT', 'ddl', false, false],
            'truncate' => ['TRUNCATE TABLE posts', 'ddl', false, false],

            'select for update' => ['SELECT * FROM posts WHERE id = 1 FOR UPDATE', 'unsafe_select', false, true],
            'select lock in share mode' => ['SELECT * FROM posts LOCK IN SHARE MODE', 'unsafe_select', false, true],
            'select into outfile' => ["SELECT * FROM posts INTO OUTFILE '/tmp/x.txt'", 'unsafe_select', false, true],
            'select sleep' => ['SELECT SLEEP(5)', 'unsafe_select', false, true],
            'select get_lock' => ["SELECT GET_LOCK('x', 10)", 'unsafe_select', false, true],

            'call procedure' => ['CALL my_proc(1)', 'other', false, false],
            'set statement' => ["SET optimizer_switch = 'index_merge=off'", 'other', false, false],
            'empty statement' => ['', 'other', false, false],
            'comment only' => ['-- nothing here', 'other', false, false],
        ];
    }

    /**
     * @dataProvider classificationProvider
     * @test
     */
    public function classify(string $sql, string $category, bool $executable, bool $explainable): void
    {
        $result = $this->classifier->classify($sql);

        $this->assertSame($category, $result->category, $sql);
        $this->assertSame($executable, $result->executable, $sql);
        $this->assertSame($explainable, $result->explainable, $sql);

        if ($executable) {
            $this->assertNull($result->skippedReason);

            return;
        }

        $this->assertNotNull($result->skippedReason);
    }
}

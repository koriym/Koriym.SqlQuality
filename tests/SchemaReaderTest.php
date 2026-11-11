<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

final class SchemaReaderTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function sqlProvider(): iterable
    {
        yield 'comma separated list' => ["SELECT u.name, p.title FROM users u, posts p WHERE u.status = 'active'", ['users', 'posts']];
        yield 'join with AS' => ['SELECT * FROM users AS u JOIN posts p ON p.user_id = u.id', ['users', 'posts']];
        yield 'no alias' => ['SELECT * FROM posts WHERE view_count > 1000', ['posts']];
        yield 'derived table' => ['SELECT * FROM (SELECT user_id FROM orders) o JOIN users u ON u.id = o.user_id', ['orders', 'users']];
        yield 'multi table update' => ['UPDATE posts p JOIN comments c ON c.post_id = p.id SET p.view_count = 1', ['posts', 'comments']];
        yield 'same table twice' => ['SELECT * FROM posts p1 JOIN posts p2 ON p2.user_id = p1.user_id', ['posts']];
        yield 'line comment stripped' => ["SELECT * FROM posts -- FROM comments\nWHERE id = 1", ['posts']];
    }

    /**
     * @param list<string> $expected
     *
     * @dataProvider sqlProvider
     */
    public function testExtractTableNames(string $sql, array $expected): void
    {
        $this->assertSame($expected, SchemaReader::tableNames($sql));
    }

    public function testADoubleDashInsideALiteralIsNotAComment(): void
    {
        $this->assertSame(['posts', 'comments'], SchemaReader::tableNames("SELECT * FROM posts p WHERE p.title = 'a -- b' AND EXISTS (SELECT 1 FROM comments c WHERE c.post_id = p.id)"));
    }

    public function testASchemaQualifiedTableKeepsItsQualifier(): void
    {
        $this->assertSame(['reporting.posts', 'users'], SchemaReader::tableNames('SELECT * FROM reporting.posts p JOIN users u ON u.id = p.user_id'));
        $this->assertSame(['reporting.posts'], SchemaReader::tableNames('SELECT * FROM `reporting`.`posts` WHERE id = 1'));
    }

    public function testTablesSharingAnAliasAreAllListed(): void
    {
        $this->assertSame(['posts', 'comments'], SchemaReader::tableNames('SELECT * FROM posts p WHERE EXISTS (SELECT 1 FROM comments p WHERE p.id = 1)'));
    }

    public function testAParenthesisOrFromInsideAStringLiteralIsNotSyntax(): void
    {
        $this->assertSame(['posts'], SchemaReader::tableNames("SELECT 'total (' AS label, p.* FROM posts p WHERE DATE(p.created_at) = '2026-06-07'"));
        $this->assertSame(['posts', 'users', 'comments'], SchemaReader::tableNames("SELECT * FROM posts p JOIN users u ON u.name = 'x (' JOIN comments c ON c.post_id = p.id"));
        $this->assertSame(['posts'], SchemaReader::tableNames("SELECT TRIM(')' FROM p.title) FROM posts p WHERE p.title <> 'a FROM b.c'"));
    }

    public function testAJoinInsideParenthesesIsStillATableReference(): void
    {
        $this->assertSame(['users', 'comments'], SchemaReader::tableNames('SELECT * FROM users u JOIN (posts p JOIN comments c ON c.post_id = p.id) ON p.user_id = u.id'));
        $this->assertSame(['users', 'posts'], SchemaReader::tableNames('SELECT TRIM((SELECT name FROM users LIMIT 1)) FROM posts'));
    }

    public function testAQuoteInsideABacktickIdentifierDoesNotOpenALiteral(): void
    {
        $this->assertSame(['posts'], SchemaReader::tableNames("SELECT p.id AS `user's` FROM posts p WHERE DATE(p.created_at) = '2026-06-07'"));
        $this->assertSame(['posts'], SchemaReader::tableNames('SELECT `a"b` FROM posts p WHERE p.title = "y"'));
    }

    public function testABackslashEndsALiteralUnderNoBackslashEscapes(): void
    {
        $sql = "SELECT 'C:\\' AS path, p.* FROM posts p WHERE p.title = 'x'";

        $this->assertSame(['posts'], SchemaReader::tableNames($sql, new QuoteRules(backslashEscapes: false)));
    }

    public function testADoubleQuotedTableIsNotReadAsAnAliasUnderAnsiQuotes(): void
    {
        $this->assertSame([], SchemaReader::tableNames('SELECT * FROM "posts" p', new QuoteRules(ansiQuotes: true)));
        $this->assertSame(['posts'], SchemaReader::tableNames('SELECT MID(p.title FROM 2) FROM posts p'));
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

final class AIQueryAdvisorTest extends TestCase
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
        $this->assertSame($expected, (new AIQueryAdvisor(''))->extractTableNames($sql));
    }
}

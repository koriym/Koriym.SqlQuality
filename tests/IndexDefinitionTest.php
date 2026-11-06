<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\InvalidIndexDefinition;
use PHPUnit\Framework\TestCase;

use function str_repeat;
use function strlen;

final class IndexDefinitionTest extends TestCase
{
    public function testParsesTheDdlOfASuggestion(): void
    {
        $index = IndexDefinition::parse('CREATE INDEX idx_posts_user_id_status ON posts (user_id, status);');

        $this->assertSame('posts', $index->table);
        $this->assertSame('idx_posts_user_id_status', $index->name);
        $this->assertSame(['user_id', 'status'], $index->columnNames());
        $this->assertSame('CREATE INDEX `idx_posts_user_id_status` ON `posts` (`user_id`, `status`)', $index->createStatement());
        $this->assertSame('ALTER TABLE `posts` ADD INDEX `idx_posts_user_id_status` (`user_id`, `status`) INVISIBLE, ALGORITHM=INPLACE, LOCK=NONE', $index->addInvisibleStatement());
        $this->assertSame('DROP INDEX `idx_posts_user_id_status` ON `posts`', $index->dropStatement());
        $this->assertSame('ALTER TABLE `posts` ALTER INDEX `idx_posts_user_id_status` VISIBLE', $index->makeVisibleStatement());
    }

    public function testParsesTheShortFormWithPrefixLengthAndOrder(): void
    {
        $index = IndexDefinition::parse('`comments`(post_id, content(20) DESC)');

        $this->assertSame('comments', $index->table);
        $this->assertSame('sqlq_trial_comments_post_id_content', $index->name);
        $this->assertSame('CREATE INDEX `sqlq_trial_comments_post_id_content` ON `comments` (`post_id`, `content`(20) DESC)', $index->createStatement());
    }

    public function testAGeneratedNameIsCutToTheIdentifierLimit(): void
    {
        $index = IndexDefinition::parse('t(' . str_repeat('c', 70) . ')');

        $this->assertSame(64, strlen($index->name));
    }

    /** @return iterable<string, array{string}> */
    public static function invalidProvider(): iterable
    {
        yield 'stacked statement' => ['posts(view_count); DROP TABLE posts'];
        yield 'expression' => ['posts((view_count + 1))'];
        yield 'quoted name with space' => ['CREATE INDEX `a b` ON posts (id)'];
        yield 'unique index' => ['CREATE UNIQUE INDEX u ON posts (id)'];
        yield 'empty column list' => ['posts()'];
        yield 'name too long' => ['CREATE INDEX ' . str_repeat('n', 65) . ' ON posts (id)'];
    }

    /** @dataProvider invalidProvider */
    public function testAnythingButAPlainIndexIsRejected(string $definition): void
    {
        $this->expectException(InvalidIndexDefinition::class);

        IndexDefinition::parse($definition);
    }
}

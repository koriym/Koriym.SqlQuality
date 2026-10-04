<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\QueryContext;
use PHPUnit\Framework\TestCase;

final class IndexSuggestionTest extends TestCase
{
    public function testCreatesACompositeIndexDdlWhenNoIndexCoversTheColumns(): void
    {
        $suggestion = IndexSuggestion::create(self::context(), 'p', ['status', 'created_at']);

        $this->assertNotNull($suggestion);
        $this->assertSame('index', $suggestion['kind']);
        $this->assertSame('CREATE INDEX idx_posts_status_created_at ON posts (status, created_at)', $suggestion['ddl']);
    }

    public function testReturnsNullWhenAnIndexAlreadyStartsWithTheseColumns(): void
    {
        $this->assertNull(IndexSuggestion::create(self::context(), 'p', ['user_id']));
    }

    public function testReturnsNullForEmptyColumns(): void
    {
        $this->assertNull(IndexSuggestion::create(self::context(), 'p', []));
    }

    private static function context(): QueryContext
    {
        return new QueryContext(
            sql: 'SELECT * FROM posts p',
            explain: ['query_block' => [], 'analyze_result' => []],
            explainAnalyze: null,
            warnings: [],
            schema: [
                'posts' => [
                    'indexes' => [
                        ['INDEX_NAME' => 'PRIMARY', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'id'],
                        ['INDEX_NAME' => 'idx_posts_user_id', 'SEQ_IN_INDEX' => 1, 'COLUMN_NAME' => 'user_id'],
                    ],
                ],
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\IndexAlreadyExists;
use Koriym\SqlQuality\Exception\UnboundParameter;

final class IndexTrialTest extends MySqlTestCase
{
    public function testUsesIndexFindsTheKeyAloneOrInAnIndexMerge(): void
    {
        $plan = ['query_block' => ['nested_loop' => [['table' => ['key' => 'PRIMARY']], ['table' => ['key' => 'idx_a,idx_trial']]]]];

        $this->assertTrue(IndexTrial::usesIndex($plan, 'idx_trial'));
        $this->assertFalse(IndexTrial::usesIndex($plan, 'idx_tri'));
    }

    public function testChangePercentIsNegativeForAnImprovement(): void
    {
        $this->assertSame(-50.0, IndexTrial::changePercent(100.0, 50.0));
        $this->assertSame(0.0, IndexTrial::changePercent(0.0, 50.0));
    }

    public function testAnExistingIndexNameIsRefusedBeforeAnyDdl(): void
    {
        $pdo = $this->connect();

        $this->expectException(IndexAlreadyExists::class);

        (new IndexTrial($pdo, new SqlFileAnalyzer($pdo)))->run('SELECT * FROM posts WHERE user_id = 1', [], IndexDefinition::parse('CREATE INDEX idx_posts_user_id ON posts (user_id)'));
    }

    public function testAnUnboundParameterFailsBeforeTheIndexIsAdded(): void
    {
        $pdo = $this->connect();

        try {
            (new IndexTrial($pdo, new SqlFileAnalyzer($pdo)))->run('SELECT * FROM posts WHERE view_count > :min', [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('UnboundParameter expected');
        } catch (UnboundParameter) {
            $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
        }
    }

    public function testKeepLeavesAnEffectiveIndexVisible(): void
    {
        $pdo = $this->connect();
        $index = IndexDefinition::parse('posts(view_count)');

        try {
            $trial = (new IndexTrial($pdo, new SqlFileAnalyzer($pdo)))->run('SELECT * FROM posts WHERE view_count > 9900', [], $index, keep: true);

            $this->assertTrue($trial['kept']);
            $this->assertSame('YES', $pdo->query("SELECT IS_VISIBLE FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
        } finally {
            $pdo->exec($index->dropStatement());
        }
    }

    public function testKeepDropsAnIneffectiveIndex(): void
    {
        $pdo = $this->connect();

        $trial = (new IndexTrial($pdo, new SqlFileAnalyzer($pdo)))->run('SELECT * FROM posts WHERE view_count > 10', [], IndexDefinition::parse('posts(view_count)'), keep: true);

        $this->assertFalse($trial['kept']);
        $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
    }

    public function testTheSessionOptimizerSwitchIsRestored(): void
    {
        $pdo = $this->connect();
        $before = $pdo->query('SELECT @@session.optimizer_switch')->fetchColumn();

        (new IndexTrial($pdo, new SqlFileAnalyzer($pdo)))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));

        $this->assertSame($before, $pdo->query('SELECT @@session.optimizer_switch')->fetchColumn());
    }
}

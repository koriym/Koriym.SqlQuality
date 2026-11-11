<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\ActiveTransactionRejected;
use Koriym\SqlQuality\Exception\IndexAlreadyExists;
use Koriym\SqlQuality\Exception\InvisibleIndexesPresent;
use Koriym\SqlQuality\Exception\UnboundParameter;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

use function array_filter;
use function array_values;
use function bin2hex;
use function preg_match;
use function random_bytes;

final class IndexTrialTest extends MySqlTestCase
{
    public function testUsesIndexFindsTheKeyAloneOrInAnIndexMerge(): void
    {
        $plan = ['query_block' => ['nested_loop' => [['table' => ['key' => 'PRIMARY']], ['table' => ['key' => 'sort_union(idx_a,idx_trial)']]]]];

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

        (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE user_id = 1', [], IndexDefinition::parse('CREATE INDEX idx_posts_user_id ON posts (user_id)'));
    }

    public function testAnUnboundParameterFailsBeforeTheIndexIsAdded(): void
    {
        $this->connect();
        $pdo = new RecordingPdo($this->connectionSettings());

        try {
            (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > :min', [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('UnboundParameter expected');
        } catch (UnboundParameter) {
            $ddl = array_filter($pdo->statements, static fn (string $statement): bool => preg_match('/^\s*(ALTER|CREATE|DROP)\b/i', $statement) === 1);
            $this->assertSame([], array_values($ddl), 'no DDL may run before the parameters bind');
            $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
        }
    }

    public function testKeepLeavesAnEffectiveIndexVisible(): void
    {
        $pdo = $this->connect();
        $index = IndexDefinition::parse('posts(view_count)');

        try {
            $trial = (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 9900', [], $index, keep: true);

            $this->assertTrue($trial['kept']);
            $this->assertSame('YES', $pdo->query("SELECT IS_VISIBLE FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
        } finally {
            $pdo->exec($index->dropStatement());
        }
    }

    public function testKeepDropsAnIneffectiveIndex(): void
    {
        $pdo = $this->connect();

        $trial = (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 10', [], IndexDefinition::parse('posts(view_count)'), keep: true);

        $this->assertFalse($trial['kept']);
        $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
    }

    public function testTheSessionOptimizerSwitchIsRestored(): void
    {
        $pdo = $this->connect();
        $before = $pdo->query('SELECT @@session.optimizer_switch')->fetchColumn();

        (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));

        $this->assertSame($before, $pdo->query('SELECT @@session.optimizer_switch')->fetchColumn());
    }

    public function testAnotherInvisibleIndexOnTheTableIsRefusedBeforeAnyDdl(): void
    {
        $pdo = $this->connect();
        $pdo->exec('ALTER TABLE posts ADD INDEX sqlq_test_hidden (view_count) INVISIBLE');

        try {
            (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count, status)'));
            $this->fail('InvisibleIndexesPresent expected');
        } catch (InvisibleIndexesPresent $e) {
            $this->assertSame('posts.sqlq_test_hidden', $e->getMessage());
            $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count_status'")->fetchColumn());
        } finally {
            $pdo->exec('DROP INDEX sqlq_test_hidden ON posts');
        }
    }

    public function testAnInvisibleIndexOnAQualifiedTableOfAnotherSchemaIsRefused(): void
    {
        $pdo = $this->connect();
        $schema = 'sqlquality_other_' . bin2hex(random_bytes(4));
        $pdo->exec("CREATE DATABASE {$schema}");

        try {
            $pdo->exec("CREATE TABLE {$schema}.comments (id INT PRIMARY KEY, post_id INT, INDEX sqlq_test_hidden (post_id) INVISIBLE)");
            (new IndexTrial($pdo))->run("SELECT * FROM posts p JOIN {$schema}.comments c ON c.post_id = p.id WHERE p.view_count > 9900", [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('InvisibleIndexesPresent expected');
        } catch (InvisibleIndexesPresent $e) {
            $this->assertSame("{$schema}.comments.sqlq_test_hidden", $e->getMessage());
        } finally {
            $pdo->exec("DROP DATABASE {$schema}");
        }
    }

    public function testAnAliasQualifiedWithTheConnectionDatabaseCountsAsTheTable(): void
    {
        $pdo = $this->connect();
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->assertIsString($database);

        $trial = (new IndexTrial($pdo))->run("SELECT * FROM {$database}.posts p WHERE p.view_count > 9900", [], IndexDefinition::parse('posts(view_count)'));

        $this->assertTrue($trial['used']);
    }

    public function testTheNoticeNamesTheDropStatementBeforeTheIndexIsAdded(): void
    {
        $pdo = $this->connect();
        $notices = [];
        $notice = static function (string $message) use (&$notices, $pdo): void {
            $notices[] = $message;
            // the notice comes before the ALTER: the index must not exist yet
            TestCase::assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
        };

        (new IndexTrial($pdo, $notice))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));

        $this->assertCount(1, $notices);
        $this->assertStringContainsString('DROP INDEX `sqlq_trial_posts_view_count` ON `posts`', $notices[0]);
    }

    public function testTheSessionLockWaitTimeoutIsLoweredForTheDdlAndRestored(): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION lock_wait_timeout = 600');
        $seen = null;
        $notice = static function () use (&$seen, $pdo): void {
            $seen = (int) $pdo->query('SELECT @@session.lock_wait_timeout')->fetchColumn();
        };

        (new IndexTrial($pdo, $notice, lockWaitTimeout: 3))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));

        $this->assertSame(3, $seen);
        $this->assertSame(600, (int) $pdo->query('SELECT @@session.lock_wait_timeout')->fetchColumn());
    }

    public function testAnAlterThatTimesOutBehindAnOpenTransactionLeavesNoIndex(): void
    {
        $pdo = $this->connect();
        $holder = $this->connect();
        $holder->beginTransaction();
        $holder->query('SELECT id FROM posts LIMIT 1')->fetchAll();

        try {
            (new IndexTrial($pdo, lockWaitTimeout: 1))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('the ALTER must time out while the transaction holds the metadata lock');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Lock wait timeout', $e->getMessage());
        } finally {
            $holder->rollBack();
        }

        $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
        $this->assertNotSame(1, (int) $pdo->query('SELECT @@session.lock_wait_timeout')->fetchColumn());
    }

    public function testAnOpenTransactionIsRefusedBeforeAnyDdl(): void
    {
        $pdo = $this->connect();
        $pdo->beginTransaction();

        try {
            (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('ActiveTransactionRejected expected');
        } catch (ActiveTransactionRejected) {
            $this->assertTrue($pdo->inTransaction(), 'the transaction must not have been committed by DDL');
        } finally {
            $pdo->rollBack();
        }
    }

    public function testUsesIndexCountsOnlyTheTableAndItsAliases(): void
    {
        $plan = [
            'query_block' => [
                'nested_loop' => [
                    ['table' => ['table_name' => 'c', 'key' => 'idx_trial']],
                    ['table' => ['table_name' => 'p', 'key' => 'PRIMARY']],
                ],
            ],
        ];

        $this->assertFalse(IndexTrial::usesIndex($plan, 'idx_trial', ['posts', 'p']));
        $this->assertTrue(IndexTrial::usesIndex($plan, 'idx_trial', ['comments', 'c']));
    }

    public function testAReductionOfExactlyTheMinimumIsEffective(): void
    {
        $before = FakeAnalysisRun::result(100.0);
        $after = FakeAnalysisRun::result(95.0);

        $this->assertTrue(IndexTrial::isEffective($before, $after, true, 5.0));
        $this->assertFalse(IndexTrial::isEffective($before, FakeAnalysisRun::result(95.01), true, 5.0));
        $this->assertFalse(IndexTrial::isEffective($before, $after, false, 5.0));
    }

    public function testAnIndexAnotherConnectionAddedMeanwhileIsNotDropped(): void
    {
        $other = $this->connect();
        // between the absence check and this trial's ALTER, another connection adds an index of the same name
        $pdo = new HookedPdo($this->connectionSettings(), 'ALTER TABLE `posts` ADD INDEX', static function () use ($other): void {
            $other->exec('ALTER TABLE posts ADD INDEX sqlq_trial_posts_view_count (view_count)');
        });

        try {
            (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('the ALTER must fail with a duplicate key name');
        } catch (PDOException $e) {
            $this->assertStringContainsString('Duplicate key name', $e->getMessage());
            $this->assertSame('YES', $other->query("SELECT IS_VISIBLE FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn(), "the other connection's index must survive");
        } finally {
            $other->exec('DROP INDEX sqlq_trial_posts_view_count ON posts');
        }
    }

    public function testAFailureRightAfterTheFirstSessionChangeStillRestoresTheSession(): void
    {
        // stands in for a signal delivered just after SET SESSION lock_wait_timeout returns
        $pdo = new HookedPdo($this->connectionSettings(), 'SET SESSION lock_wait_timeout = 10', static function (): void {
            throw new RuntimeException('interrupted');
        }, after: true);
        $pdo->exec('SET SESSION lock_wait_timeout = 600');

        try {
            (new IndexTrial($pdo))->run('SELECT * FROM posts WHERE view_count > 9900', [], IndexDefinition::parse('posts(view_count)'));
            $this->fail('the injected failure must propagate');
        } catch (RuntimeException $e) {
            $this->assertSame('interrupted', $e->getMessage());
        }

        $this->assertSame(600, (int) $pdo->query('SELECT @@session.lock_wait_timeout')->fetchColumn());
        $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
    }
}

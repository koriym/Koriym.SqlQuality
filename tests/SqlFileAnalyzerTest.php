<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\NotExplainable;
use PDOException;
use RuntimeException;

use function array_column;
use function array_keys;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
use function sprintf;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SqlFileAnalyzerTest extends MySqlTestCase
{
    private string $outputDir = '';

    protected function tearDown(): void
    {
        if ($this->outputDir === '' || ! is_dir($this->outputDir)) {
            return;
        }

        foreach ((array) scandir($this->outputDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            unlink($this->outputDir . '/' . $entry);
        }

        rmdir($this->outputDir);
    }

    private function createAnalyzer(): SqlFileAnalyzer
    {
        $pdo = $this->connect();

        return new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/sql');
    }

    public function testAnalyzeSqlFilesReportsSkippedFilesWithoutWritingToStdout(): void
    {
        $this->expectOutputString('');

        $run = $this->createAnalyzer()->analyzeSQLFiles([
            '1_full_table_scan.sql' => ['min_views' => 1000],
            '14_not_found.sql' => [],
            '17_empty_list.sql' => ['empty_list' => []],
        ]);

        $this->assertCount(1, $run['results']);
        $this->assertArrayHasKey('without_optimizer', $run['results']['1_full_table_scan.sql']);
        $this->assertContains('FullTableScan', array_column($run['results']['1_full_table_scan.sql']['issues'], 'type'));

        $this->assertCount(2, $run['skipped']);
        $this->assertNotSame('', $run['skipped']['14_not_found.sql']);
        $this->assertNotSame('', $run['skipped']['17_empty_list.sql']);
    }

    public function testQueryContextCarriesInterpolatedSqlPlanAndSchema(): void
    {
        $context = $this->createAnalyzer()->queryContext('1_full_table_scan.sql', ['min_views' => 1000]);

        $this->assertStringContainsString('view_count > 1000', $context->sql);
        $this->assertSame('posts', $context->explain['query_block']['table']['table_name']);
        $this->assertStringStartsWith('-> ', (string) $context->explainAnalyze);
        $this->assertCount(1, $context->warningsWithCode(1003));
        $this->assertSame(['posts'], array_keys($context->schema));
        $this->assertSame(['id'], $context->primaryKeyColumns('posts'));
    }

    public function testQueryContextSkipsExplainAnalyzeForDml(): void
    {
        $context = $this->createAnalyzer()->queryContext('20_multi_table_update.sql', []);

        $this->assertNull($context->explainAnalyze);
        $this->assertCount(1, $context->warningsWithCode(1003));
        $this->assertSame(['p' => 'posts', 'c' => 'comments'], $context->aliases());
    }

    public function testQueryContextCarriesTheOptimizerTraceAndRestoresTheSessionVariable(): void
    {
        $pdo = $this->connect();
        $pdo->exec("SET optimizer_trace = 'enabled=off,one_line=on'");
        $analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/sql');

        $context = $analyzer->queryContext('9_inefficient_in_query.sql', ['status1' => 'draft', 'status2' => 'published', 'status3' => 'archived', 'status4' => 'deleted', 'status5' => 'pending']);

        $this->assertSame('cost', $context->optimizerTraceFor('posts')['range_analysis']['analyzing_range_alternatives']['range_scan_alternatives'][0]['cause'] ?? null);
        $this->assertSame('enabled=off,one_line=on', $pdo->query('SELECT @@optimizer_trace')->fetchColumn());
    }

    public function testAFailingExplainStillRestoresTheOptimizerTraceAndIsNotMaskedByARestoreFailure(): void
    {
        $pdo = $this->connect();
        $before = $pdo->query('SELECT @@optimizer_trace')->fetchColumn();
        $this->writeSqlFile('zz_missing_table.sql', 'SELECT * FROM no_such_table');

        try {
            (new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $this->outputDir))->queryContext('zz_missing_table.sql', []);
            $this->fail('EXPLAIN on a missing table must throw');
        } catch (PDOException $e) {
            $this->assertStringContainsString('no_such_table', $e->getMessage());
        }

        $this->assertSame($before, $pdo->query('SELECT @@optimizer_trace')->fetchColumn());

        $failingRestore = new RestoreFailingPdo($this->connectionSettings());
        try {
            (new SqlFileAnalyzer($failingRestore, new ExplainAnalyzer(), $this->outputDir))->queryContext('zz_missing_table.sql', []);
            $this->fail('EXPLAIN on a missing table must throw');
        } catch (PDOException $e) {
            $this->assertStringContainsString('no_such_table', $e->getMessage(), 'the EXPLAIN error wins over the restore error');
        }
    }

    public function testABackslashEscapedQuoteFollowedByASemicolonIsOneStatementUnderTheDefaultSqlMode(): void
    {
        $pdo = $this->connect();
        $this->writeSqlFile('zz_escaped_quote.sql', "SELECT 'it\\'s; fine' AS s");

        $context = (new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $this->outputDir))->queryContext('zz_escaped_quote.sql', []);

        $this->assertSame(1, $context->explain['query_block']['select_id'] ?? null);
    }

    public function testTheSameStatementIsRejectedAsStackedUnderNoBackslashEscapes(): void
    {
        $pdo = $this->connect();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@session.sql_mode, ',NO_BACKSLASH_ESCAPES')");
        $this->writeSqlFile('zz_escaped_quote.sql', "SELECT 'x\\'; SET @smuggled = 1 -- '");

        try {
            (new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $this->outputDir))->queryContext('zz_escaped_quote.sql', []);
            $this->fail('a second statement hidden by NO_BACKSLASH_ESCAPES must not reach the server');
        } catch (NotExplainable $e) {
            $this->assertStringContainsString('stacked', $e->getMessage());
        }

        $this->assertNull($pdo->query('SELECT @smuggled')->fetchColumn());
    }

    public function testABackslashInsideAnAnsiQuotedIdentifierIsRejectedAsStacked(): void
    {
        $pdo = $this->connect();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@session.sql_mode, ',ANSI_QUOTES')");
        $this->writeSqlFile('zz_ansi_quotes.sql', 'SELECT 1 AS "x\\"; SET @smuggled = 1 -- "');

        try {
            (new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $this->outputDir))->queryContext('zz_ansi_quotes.sql', []);
            $this->fail('a second statement hidden by ANSI_QUOTES must not reach the server');
        } catch (NotExplainable $e) {
            $this->assertStringContainsString('stacked', $e->getMessage());
        }

        $this->assertNull($pdo->query('SELECT @smuggled')->fetchColumn());
    }

    public function testASqlModeChangedAfterConstructionIsHonoured(): void
    {
        $pdo = $this->connect();
        $this->writeSqlFile('zz_escaped_quote.sql', "SELECT 'x\\'; SET @smuggled = 1 -- '");
        $analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $this->outputDir);
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@session.sql_mode, ',NO_BACKSLASH_ESCAPES')");

        try {
            $analyzer->queryContext('zz_escaped_quote.sql', []);
            $this->fail('the mode at the time of the call must decide, not the mode at construction');
        } catch (NotExplainable $e) {
            $this->assertStringContainsString('stacked', $e->getMessage());
        }

        $this->assertNull($pdo->query('SELECT @smuggled')->fetchColumn());
    }

    public function testARestoreFailureAfterASuccessfulExplainPropagates(): void
    {
        $pdo = new RestoreFailingPdo($this->connectionSettings());
        $analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/sql');

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage(RestoreFailingPdo::MESSAGE);

        $analyzer->queryContext('1_full_table_scan.sql', ['min_views' => 1000]);
    }

    public function testTheNoOptimizerPassFailureWinsOverAFailingSettingsRestore(): void
    {
        $analyzer = new SqlFileAnalyzer($this->connect(), new ExplainAnalyzer(), __DIR__ . '/sql', new FailingOptimizerSettings(disableFails: true));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(FailingOptimizerSettings::DISABLE_MESSAGE);

        $analyzer->analyze('1_full_table_scan.sql', ['min_views' => 1000]);
    }

    public function testAFailingSettingsRestoreAfterASuccessfulNoOptimizerPassPropagates(): void
    {
        $analyzer = new SqlFileAnalyzer($this->connect(), new ExplainAnalyzer(), __DIR__ . '/sql', new FailingOptimizerSettings(disableFails: false));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(FailingOptimizerSettings::RESTORE_MESSAGE);

        $analyzer->analyze('1_full_table_scan.sql', ['min_views' => 1000]);
    }

    private function writeSqlFile(string $name, string $sql): void
    {
        $this->outputDir = sys_get_temp_dir() . '/' . uniqid('sqlquality_sql_', true);
        mkdir($this->outputDir);
        file_put_contents($this->outputDir . '/' . $name, $sql);
    }

    public function testExplainReturnsTheResultWithTheContextOfTheOptimizerEnabledPass(): void
    {
        ['result' => $result, 'context' => $context] = $this->createAnalyzer()
            ->explain('11_nested_loop.sql', ['email' => 'example@example.com']);

        $this->assertNotSame($result['cost'], $result['without_optimizer']['cost']);
        $this->assertContains('FullTableScan@posts', SqlFileAnalyzer::hiddenIssues($result));
        $this->assertSame($result['cost'], (float) $context->explain['query_block']['cost_info']['query_cost']);
        $this->assertStringContainsString("'example@example.com'", $context->sql);
        $this->assertStringStartsWith('-> ', (string) $context->explainAnalyze);
    }

    public function testAnalyzeDirectoryCoversEverySqlFileAndEveryParamsEntry(): void
    {
        $this->writeSqlFile('a.sql', 'SELECT * FROM posts WHERE view_count > :min_views');
        file_put_contents($this->outputDir . '/b.sql', 'SELECT 1');
        file_put_contents($this->outputDir . '/notes.txt', 'not sql');

        $run = (new SqlFileAnalyzer($this->connect(), new ExplainAnalyzer(), $this->outputDir))->analyzeDirectory(['missing.sql' => []]);

        $this->assertSame(['b.sql'], array_keys($run['results']));
        $this->assertSame('UnboundParameter: min_views', $run['skipped']['a.sql']);
        $this->assertStringStartsWith('RuntimeException: SQL file not found', $run['skipped']['missing.sql']);
    }

    public function testAnalyzeSqlTakesTheStatementText(): void
    {
        $result = $this->createAnalyzer()->analyzeSql('SELECT * FROM posts WHERE view_count > :min_views', ['min_views' => 1000]);

        $this->assertTrue($result['executed']);
        $this->assertIsFloat($result['execution_time']);
        $this->assertSame(['FullTableScan@posts'], array_column($result['issues'], 'id'));
    }

    public function testAWriteStatementIsExplainedButNotTimed(): void
    {
        $result = $this->createAnalyzer()->analyze('20_multi_table_update.sql', []);

        $this->assertFalse($result['executed']);
        $this->assertNull($result['execution_time']);
        $this->assertNotNull($result['skipped_reason']);
    }

    public function testADirectoryNameWithGlobCharactersIsReadLiterally(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/' . uniqid('sqlquality_[archive]_', true);
        mkdir($this->outputDir);
        file_put_contents($this->outputDir . '/a.sql', 'SELECT 1');

        $this->assertSame(['a.sql'], (new SqlFileAnalyzer($this->connect(), new ExplainAnalyzer(), $this->outputDir))->sqlFiles());
    }

    public function testATableOfAnotherSchemaGetsItsOwnSchemaAndTheSameFindings(): void
    {
        $pdo = $this->connect();
        $schema = $this->createScratchDatabase($pdo, 'sqlquality_reporting');

        try {
            $pdo->exec("CREATE TABLE {$schema}.posts LIKE posts");
            $pdo->exec("INSERT INTO {$schema}.posts SELECT * FROM posts");
            $pdo->query("ANALYZE TABLE {$schema}.posts")->fetchAll();

            $analyzer = new SqlFileAnalyzer($pdo);
            $sql = "SELECT * FROM %s p WHERE DATE(p.created_at) = '2026-06-07'";
            ['result' => $qualified, 'context' => $context] = $analyzer->explainSql(sprintf($sql, $schema . '.posts'), []);
            $local = $analyzer->analyzeSql(sprintf($sql, 'posts'), []);

            $this->assertSame([$schema . '.posts'], array_keys($context->schema));
            $this->assertSame(['p' => $schema . '.posts'], $context->aliases());
            $this->assertNotSame([], $context->indexColumns('p'));
            $this->assertSame(array_column($local['issues'], 'id'), array_column($qualified['issues'], 'id'));
            $this->assertContains('FunctionInvalidatesIndex@p.created_at', array_column($qualified['issues'], 'id'));
        } finally {
            $pdo->exec("DROP DATABASE {$schema}");
        }
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_column;
use function array_merge;
use function copy;
use function dirname;
use function fclose;
use function file_put_contents;
use function fwrite;
use function getenv;
use function glob;
use function is_dir;
use function json_decode;
use function json_encode;
use function mkdir;
use function proc_close;
use function proc_open;
use function rmdir;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

final class CliTest extends MySqlTestCase
{
    private string $workDir = '';

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/' . uniqid('sqlquality_cli_', true);
        mkdir($this->workDir);
        mkdir($this->workDir . '/sql');
        copy(__DIR__ . '/sql/1_full_table_scan.sql', $this->workDir . '/sql/1_full_table_scan.sql');
        file_put_contents($this->workDir . '/params.php', <<<'PARAMS'
        <?php

        return [
            '1_full_table_scan.sql' => ['min_views' => 1000],
            '14_not_found.sql' => [],
        ];
        PARAMS);
    }

    protected function tearDown(): void
    {
        foreach (['/sql/*', '/*'] as $pattern) {
            foreach (glob($this->workDir . $pattern) ?: [] as $path) {
                if (is_dir($path)) {
                    rmdir($path);
                    continue;
                }

                unlink($path);
            }
        }

        rmdir($this->workDir);
    }

    public function testHelpAndVersionExitWithZero(): void
    {
        [$exitCode, $stdout] = $this->runCli(['--help']);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('try-index', $stdout);

        [$exitCode, $stdout] = $this->runCli(['--version']);
        $this->assertSame(0, $exitCode);
        $this->assertSame('sql-quality ' . Cli::VERSION . "\n", $stdout);
    }

    public function testUnknownCommandAndUnknownOptionExitWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['optimize']);
        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Unknown command: optimize', $stderr);

        [$exitCode, $stdout, $stderr] = $this->runCli(['analyze', '--sql-dir=' . $this->workDir . '/sql', '--format=json']);
        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('analyze does not accept --format', $stderr);
    }

    public function testFailOnCriticalExitsWithOne(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->analyze(['--fail-on=critical']);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stderr);

        $report = json_decode($stdout, true);
        $this->assertIsArray($report);
        $this->assertSame(['FullTableScan@posts'], array_column($report['queries']['1_full_table_scan.sql']['issues'], 'id'));
        $this->assertArrayHasKey('14_not_found.sql', $report['skipped']);
    }

    public function testWithoutFailOnExitsWithZero(): void
    {
        $this->connect();

        [$exitCode] = $this->analyze([]);

        $this->assertSame(0, $exitCode);
    }

    public function testAnalyzeWithoutParamsCoversEveryFileAndNamesTheUnboundParameter(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/sql/2_plain.sql', 'SELECT id FROM users WHERE id = 1');

        [$exitCode, $stdout] = $this->runCli(['analyze', '--sql-dir=' . $this->workDir . '/sql']);

        $report = json_decode($stdout, true);
        $this->assertSame(0, $exitCode);
        $this->assertSame('UnboundParameter: min_views', $report['skipped']['1_full_table_scan.sql']);
        $this->assertArrayHasKey('2_plain.sql', $report['queries']);
    }

    public function testAnalyzeTakesAJsonParamsFile(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/params.json', '{"1_full_table_scan.sql": {"min_views": 1000}}');

        [$exitCode, $stdout, $stderr] = $this->runCli(['analyze', '--sql-dir=' . $this->workDir . '/sql', '--params=' . $this->workDir . '/params.json']);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);
        $this->assertSame(1, json_decode($stdout, true)['summary']['analyzed']);
    }

    public function testBaselineFailsOnlyOnNewIssues(): void
    {
        $this->connect();

        [, $first] = $this->analyze([]);
        file_put_contents($this->workDir . '/baseline.json', $first);

        [$exitCode, $stdout] = $this->analyze(['--baseline=' . $this->workDir . '/baseline.json', '--fail-on=critical']);
        $report = json_decode($stdout, true);
        $this->assertSame(0, $exitCode, 'a known critical issue must not fail the run');
        $this->assertSame([['file' => '1_full_table_scan.sql', 'id' => 'FullTableScan@posts', 'severity' => 'Critical']], $report['baseline']['persisting']);
        $this->assertSame([], $report['baseline']['new']);

        $emptied = json_decode($first, true);
        $emptied['queries']['1_full_table_scan.sql']['issues'] = [];
        file_put_contents($this->workDir . '/baseline.json', json_encode($emptied));

        [$exitCode, $stdout] = $this->analyze(['--baseline=' . $this->workDir . '/baseline.json', '--fail-on=critical']);
        $this->assertSame(1, $exitCode);
        $this->assertSame('FullTableScan@posts', json_decode($stdout, true)['baseline']['new'][0]['id']);
    }

    public function testBaselineFromAnOlderReportExitsWithTwo(): void
    {
        file_put_contents($this->workDir . '/baseline.json', '{"queries": {"a.sql": {"issues": [{"type": "FullTableScan", "severity": "Critical"}]}}}');

        [$exitCode, $stdout, $stderr] = $this->analyze(['--baseline=' . $this->workDir . '/baseline.json']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('InvalidBaseline', $stderr);
    }

    public function testMissingSqlDirExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['analyze']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('--sql-dir', $stderr);
    }

    public function testUnknownFailOnLevelExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->analyze(['--fail-on=bogus']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('bogus', $stderr);
    }

    public function testInvalidParamsEntryExitsWithTwo(): void
    {
        file_put_contents($this->workDir . '/bad.php', "<?php\nreturn ['query.sql' => 'invalid'];\n");

        [$exitCode, $stdout, $stderr] = $this->runCli(['analyze', '--sql-dir=' . $this->workDir . '/sql', '--params=' . $this->workDir . '/bad.php']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('query.sql', $stderr);
    }

    /** @return array<string, array{string}> */
    public function unloadableParamsFileProvider(): array
    {
        return [
            'parse error' => ["<?php\nreturn [broken\n"],
            'throws' => ["<?php\nthrow new \\Exception('boom');\n"],
        ];
    }

    /** @dataProvider unloadableParamsFileProvider */
    public function testUnloadableParamsFileExitsWithTwo(string $contents): void
    {
        file_put_contents($this->workDir . '/broken.php', $contents);

        [$exitCode, $stdout, $stderr] = $this->runCli(['analyze', '--sql-dir=' . $this->workDir . '/sql', '--params=' . $this->workDir . '/broken.php']);
        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Params file could not be loaded', $stderr);

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql-file=' . __DIR__ . '/sql/1_full_table_scan.sql', '--params=' . $this->workDir . '/broken.php']);
        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Params file could not be loaded', $stderr);
    }

    public function testExplainIsASummaryUnlessDetailIsFull(): void
    {
        $this->connect();

        [$exitCode, $stdout] = $this->runCli(['explain', '--sql-file=' . __DIR__ . '/sql/1_full_table_scan.sql', '--params={"min_views":1000}']);
        $summary = json_decode($stdout, true);
        $this->assertSame(0, $exitCode);
        $this->assertSame('1_full_table_scan.sql', $summary['file']);
        $this->assertArrayNotHasKey('context', $summary);

        [$exitCode, $stdout] = $this->runCli(['explain', '--sql-file=' . __DIR__ . '/sql/1_full_table_scan.sql', '--params={"min_views":1000}', '--detail=full']);
        $full = json_decode($stdout, true);
        $this->assertSame(0, $exitCode);
        $this->assertSame('posts', $full['context']['explain']['query_block']['table']['table_name']);
    }

    public function testExplainReadsInlineSqlAndStdin(): void
    {
        $this->connect();

        [$exitCode, $stdout] = $this->runCli(['explain', '--sql=SELECT * FROM users WHERE id = :id', '--params={"id":3}']);
        $report = json_decode($stdout, true);
        $this->assertSame(0, $exitCode);
        $this->assertNull($report['file']);
        $this->assertSame('SELECT * FROM users WHERE id = 3', $report['sql']);

        [$exitCode, $stdout] = $this->runCli(['explain', '--sql-file=-', '--params={"id":3}'], 'SELECT * FROM users WHERE id = :id');
        $this->assertSame(0, $exitCode);
        $this->assertSame('SELECT * FROM users WHERE id = 3', json_decode($stdout, true)['sql']);
    }

    public function testExplainWithBothOrNeitherSourceExitsWithTwo(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['explain']);
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Give exactly one of --sql-file=FILE and --sql=SQL', $stderr);

        [$exitCode] = $this->runCli(['explain', '--sql=SELECT 1', '--sql-file=' . __DIR__ . '/sql/12_select1.sql']);
        $this->assertSame(2, $exitCode);
    }

    public function testExplainWithAnUnboundParameterNamesIt(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql-file=' . __DIR__ . '/sql/3_function_on_indexed_column.sql']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('UnboundParameter: target_date', $stderr);
    }

    public function testExplainWithFlatListParamsFileInterpolatesTheList(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/flat.php', "<?php\nreturn ['listed_params' => ['Alice', 'Bob']];\n");

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql-file=' . __DIR__ . '/sql/15_listed_parameters.sql', '--params=' . $this->workDir . '/flat.php']);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString("IN ('Alice','Bob')", json_decode($stdout, true)['sql']);
    }

    public function testExplainCostReductionPercentIsPositiveZeroNotNegativeZeroWhenCostIsUnchanged(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql-file=' . __DIR__ . '/sql/12_select1.sql']);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('"cost_reduction_percent": 0', $stdout);
        $this->assertStringNotContainsString('"cost_reduction_percent": -0', $stdout);
    }

    public function testExplainOfDdlStatementExitsWithTwoAndReportsClassifierReason(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/ddl.sql', 'CREATE TABLE sqlquality_probe (id INT PRIMARY KEY)');

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql-file=' . $this->workDir . '/ddl.sql']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('NotExplainable: DDL statement is not explainable in WD mode', $stderr);
        $this->assertStringNotContainsString('CREATE TABLE', $stderr);
    }

    public function testExplainOfMissingTableExitsWithTwoAndReportsPdoException(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql=SELECT * FROM sqlquality_table_that_does_not_exist']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('PDOException: SQLSTATE', $stderr);
    }

    public function testExplainOfSqlWithInvalidUtf8ExitsWithTwoInsteadOfAnEmptyReport(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/bad_utf8.sql', "SELECT \"\xe9\";");

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql-file=' . $this->workDir . '/bad_utf8.sql']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('Report could not be encoded as JSON', $stderr);
    }

    public function testCompareReportsAnEquivalentRewrite(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli([
            'compare',
            '--before=' . __DIR__ . '/sql/3_function_on_indexed_column.sql',
            '--after-sql=SELECT * FROM posts WHERE created_at >= :target_date AND created_at < :target_date + INTERVAL 1 DAY',
            '--params={"target_date":"2026-06-07"}',
        ]);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);
        $report = json_decode($stdout, true);
        $this->assertTrue($report['equivalent']);
        $this->assertFalse($report['both_empty']);
        $this->assertSame($report['before']['row_count'], $report['after']['row_count']);
        $this->assertContains('FunctionInvalidatesIndex@posts.created_at', $report['issues']['resolved']);
    }

    public function testCompareGivesAnInlineRewriteTheParamsEntryOfTheFileItRewrites(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/keyed.php', "<?php\nreturn ['3_function_on_indexed_column.sql' => ['target_date' => '2026-06-07']];\n");

        [$exitCode, $stdout, $stderr] = $this->runCli([
            'compare',
            '--before=' . __DIR__ . '/sql/3_function_on_indexed_column.sql',
            '--after-sql=SELECT * FROM posts WHERE created_at >= :target_date AND created_at < :target_date + INTERVAL 1 DAY',
            '--params=' . $this->workDir . '/keyed.php',
        ]);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);
        $this->assertTrue(json_decode($stdout, true)['equivalent']);
    }

    public function testCompareExitsWithOneWhenTheRowsDiffer(): void
    {
        $this->connect();

        [$exitCode, $stdout] = $this->runCli([
            'compare',
            '--before-sql=SELECT id FROM users WHERE id <= 3',
            '--after-sql=SELECT id FROM users WHERE id < 3',
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertFalse(json_decode($stdout, true)['equivalent']);
    }

    public function testCompareRejectsAWriteStatement(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli([
            'compare',
            '--before-sql=UPDATE users SET name = name',
            '--after-sql=SELECT 1',
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('NotReadOnlySelect', $stderr);
    }

    public function testTryIndexReportsAnEffectiveIndexAndDropsIt(): void
    {
        $pdo = $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli([
            'try-index',
            '--sql=SELECT * FROM posts WHERE view_count > 9900',
            '--index=posts(view_count)',
        ]);

        $this->assertStringContainsString('if this run is interrupted, remove it with: DROP INDEX `sqlq_trial_posts_view_count` ON `posts`', $stderr);
        $this->assertSame(0, $exitCode);
        $report = json_decode($stdout, true);
        $this->assertTrue($report['used']);
        $this->assertTrue($report['effective']);
        $this->assertFalse($report['kept']);
        $this->assertSame('CREATE INDEX `sqlq_trial_posts_view_count` ON `posts` (`view_count`)', $report['index']['ddl']);
        $this->assertSame(['FullTableScan@posts'], $report['issues']['resolved']);
        $this->assertFalse($pdo->query("SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND index_name = 'sqlq_trial_posts_view_count'")->fetchColumn());
    }

    public function testTryIndexExitsWithOneForAnIndexThePlanDoesNotUse(): void
    {
        $this->connect();

        [$exitCode, $stdout] = $this->runCli([
            'try-index',
            '--sql=SELECT * FROM posts WHERE view_count > 10',
            '--index=CREATE INDEX idx_posts_view_count ON posts (view_count)',
        ]);

        $report = json_decode($stdout, true);
        $this->assertSame(1, $exitCode);
        $this->assertFalse($report['effective']);
    }

    public function testTryIndexRejectsAValueForKeepAndANegativeMinImprovement(): void
    {
        [$exitCode, , $stderr] = $this->runCli(['try-index', '--sql=SELECT 1', '--index=posts(view_count)', '--keep=0']);
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--keep is a flag', $stderr);

        [$exitCode, , $stderr] = $this->runCli(['try-index', '--sql=SELECT 1', '--index=posts(view_count)', '--min-improvement=-10']);
        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('--min-improvement', $stderr);
    }

    public function testTryIndexRejectsADefinitionThatIsNotAnIndex(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli([
            'try-index',
            '--sql=SELECT 1',
            '--index=posts(view_count); DROP TABLE posts',
        ]);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('InvalidIndexDefinition', $stderr);
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function analyze(array $args): array
    {
        return $this->runCli(array_merge(['analyze', '--sql-dir=' . $this->workDir . '/sql', '--params=' . $this->workDir . '/params.php'], $args));
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args, string $stdin = ''): array
    {
        ['dsn' => $dsn, 'user' => $user, 'password' => $password] = $this->connectionSettings();
        $env = ['SQL_QUALITY_DSN' => $dsn, 'SQL_QUALITY_USER' => $user, 'SQL_QUALITY_PASSWORD' => $password, 'PATH' => (string) getenv('PATH')];
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/sql-quality'], $args);

        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        if ($process === false) {
            $this->fail('Failed to start the CLI');
        }

        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    public function testAJsonArrayIsNotAParamsObject(): void
    {
        file_put_contents($this->workDir . '/list.json', '[7]');

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql=SELECT 1', '--params=' . $this->workDir . '/list.json']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('must be a JSON object', $stderr);
    }

    public function testWhatAParamsFilePrintsGoesToStderr(): void
    {
        $this->connect();
        file_put_contents($this->workDir . '/noisy.php', "\n<?php\necho 'debug';\nreturn [];\n");

        [$exitCode, $stdout, $stderr] = $this->runCli(['explain', '--sql=SELECT 1', '--params=' . $this->workDir . '/noisy.php']);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray(json_decode($stdout, true), 'stdout must stay a JSON report');
        $this->assertStringContainsString('debug', $stderr);
    }
}

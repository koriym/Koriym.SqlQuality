<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function array_merge;
use function dirname;
use function fclose;
use function file_put_contents;
use function is_file;
use function json_decode;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

use const PHP_BINARY;

final class CliTest extends MySqlTestCase
{
    private string $paramsFile = '';

    protected function setUp(): void
    {
        $this->paramsFile = sys_get_temp_dir() . '/' . uniqid('sqlquality_params_', true) . '.php';
        file_put_contents($this->paramsFile, <<<'PARAMS'
        <?php

        return [
            '1_full_table_scan.sql' => ['min_views' => 1000],
            '14_not_found.sql' => [],
        ];
        PARAMS);
    }

    protected function tearDown(): void
    {
        if (! is_file($this->paramsFile)) {
            return;
        }

        unlink($this->paramsFile);
    }

    public function testFailOnCriticalExitsWithOne(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runCli(['--format=json', '--fail-on=critical']);

        $this->assertSame(1, $exitCode);
        $this->assertSame('', $stderr);

        $report = json_decode($stdout, true);

        $this->assertIsArray($report);
        $this->assertArrayHasKey('summary', $report);
        $this->assertArrayHasKey('queries', $report);
        $this->assertArrayHasKey('skipped', $report);
        $this->assertArrayHasKey('14_not_found.sql', $report['skipped']);
    }

    public function testWithoutFailOnExitsWithZero(): void
    {
        $this->connect();

        [$exitCode] = $this->runCli(['--format=json']);

        $this->assertSame(0, $exitCode);
    }

    public function testMissingSqlDirExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--format=json'], false);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('--sql-dir', $stderr);
    }

    public function testUnknownFailOnLevelExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--format=json', '--fail-on=bogus']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('bogus', $stderr);
    }

    public function testMarkdownWithoutOutputExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--format=markdown']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('--output', $stderr);
    }

    public function testExplainWithFlatListParamsFileInterpolatesTheList(): void
    {
        $this->connect();

        $paramsFile = sys_get_temp_dir() . '/' . uniqid('sqlquality_flat_params_', true) . '.php';
        file_put_contents($paramsFile, "<?php\nreturn ['listed_params' => ['Alice', 'Bob']];\n");

        [$exitCode, $stdout, $stderr] = $this->runExplainCli([
            '--sql-file=' . __DIR__ . '/sql/15_listed_parameters.sql',
            '--params=' . $paramsFile,
        ]);

        unlink($paramsFile);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);

        $report = json_decode($stdout, true);
        $this->assertIsArray($report);
        $this->assertStringContainsString("IN ('Alice','Bob')", $report['sql']);
    }

    public function testExplainCostReductionPercentIsPositiveZeroNotNegativeZeroWhenCostIsUnchanged(): void
    {
        $this->connect();

        [$exitCode, $stdout, $stderr] = $this->runExplainCli([
            '--sql-file=' . __DIR__ . '/sql/12_select1.sql',
            '--params={}',
        ]);

        $this->assertSame('', $stderr);
        $this->assertSame(0, $exitCode);

        $report = json_decode($stdout, true);
        $this->assertIsArray($report);
        $this->assertStringContainsString('"cost_reduction_percent": 0', $stdout);
        $this->assertStringNotContainsString('"cost_reduction_percent": -0', $stdout);
    }

    public function testExplainOfDdlStatementExitsWithTwoAndReportsClassifierReason(): void
    {
        $sqlFile = sys_get_temp_dir() . '/' . uniqid('sqlquality_ddl_', true) . '.sql';
        file_put_contents($sqlFile, 'CREATE TABLE sqlquality_probe (id INT PRIMARY KEY)');

        [$exitCode, $stdout, $stderr] = $this->runExplainCli(['--sql-file=' . $sqlFile]);

        unlink($sqlFile);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('NotExplainable: DDL statement is not explainable in WD mode', $stderr);
        $this->assertStringNotContainsString('CREATE TABLE', $stderr);
    }

    public function testExplainOfMissingTableExitsWithTwoAndReportsPdoException(): void
    {
        $this->connect();

        $sqlFile = sys_get_temp_dir() . '/' . uniqid('sqlquality_missing_table_', true) . '.sql';
        file_put_contents($sqlFile, 'SELECT * FROM sqlquality_table_that_does_not_exist');

        [$exitCode, $stdout, $stderr] = $this->runExplainCli(['--sql-file=' . $sqlFile]);

        unlink($sqlFile);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('PDOException: SQLSTATE', $stderr);
    }

    public function testUnwritableOutputDirExitsWithTwo(): void
    {
        $this->connect();

        $blocker = sys_get_temp_dir() . '/' . uniqid('sqlquality_blocker_', true);
        file_put_contents($blocker, '');

        try {
            [$exitCode, $stdout, $stderr] = $this->runCli(['--format=markdown', '--output=' . $blocker . '/sub']);

            $this->assertSame(2, $exitCode);
            $this->assertSame('', $stdout);
            $this->assertStringContainsString('Failed to create directory', $stderr);
        } finally {
            unlink($blocker);
        }
    }

    public function testUnsupportedFormatExitsWithTwo(): void
    {
        [$exitCode, $stdout, $stderr] = $this->runCli(['--format=yaml']);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString('--format', $stderr);
    }

    public function testInvalidParamsEntryExitsWithTwo(): void
    {
        $this->connect();

        $badParamsFile = sys_get_temp_dir() . '/' . uniqid('sqlquality_badparams_', true) . '.php';
        file_put_contents($badParamsFile, <<<'PARAMS'
        <?php

        return [
            'query.sql' => 'invalid',
        ];
        PARAMS);

        try {
            [$exitCode, $stdout, $stderr] = $this->runCli(['--format=json'], true, $badParamsFile);

            $this->assertSame(2, $exitCode);
            $this->assertSame('', $stdout);
            $this->assertStringContainsString('query.sql', $stderr);
        } finally {
            unlink($badParamsFile);
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args, bool $withSqlDir = true, string|null $paramsFile = null): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/sql-quality', 'analyze', '--params=' . ($paramsFile ?? $this->paramsFile)];
        if ($withSqlDir) {
            $command[] = '--sql-dir=' . __DIR__ . '/sql';
        }

        $process = proc_open(array_merge($command, $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            $this->fail('Failed to start the CLI');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runExplainCli(array $args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/sql-quality', 'explain'], $args);

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if ($process === false) {
            $this->fail('Failed to start the CLI');
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }
}

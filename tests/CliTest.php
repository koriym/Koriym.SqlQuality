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

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args, bool $withSqlDir = true): array
    {
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/sql-quality', 'analyze', '--params=' . $this->paramsFile];
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
}

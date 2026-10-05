<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDOException;

use function array_column;
use function array_keys;
use function file_exists;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function scandir;
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

        return new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/sql', new AIQueryAdvisor(''));
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
        $this->assertArrayHasKey('optimizer_comparison', $run['results']['1_full_table_scan.sql']);
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
        $analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/sql', new AIQueryAdvisor(''));

        $context = $analyzer->queryContext('9_inefficient_in_query.sql', ['status1' => 'draft', 'status2' => 'published', 'status3' => 'archived', 'status4' => 'deleted', 'status5' => 'pending']);

        $this->assertSame('cost', $context->optimizerTraceFor('posts')['range_analysis']['analyzing_range_alternatives']['range_scan_alternatives'][0]['cause'] ?? null);
        $this->assertSame('enabled=off,one_line=on', $pdo->query('SELECT @@optimizer_trace')->fetchColumn());
    }

    public function testAFailingExplainStillRestoresTheOptimizerTraceAndIsNotMaskedByARestoreFailure(): void
    {
        $pdo = $this->connect();
        $this->writeSqlFile('zz_missing_table.sql', 'SELECT * FROM no_such_table');

        try {
            (new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $this->outputDir, new AIQueryAdvisor('')))->queryContext('zz_missing_table.sql', []);
            $this->fail('EXPLAIN on a missing table must throw');
        } catch (PDOException $e) {
            $this->assertStringContainsString('no_such_table', $e->getMessage());
        }

        $this->assertSame('enabled=off,one_line=off', $pdo->query('SELECT @@optimizer_trace')->fetchColumn());

        $failingRestore = new RestoreFailingPdo($this->connectionSettings());
        try {
            (new SqlFileAnalyzer($failingRestore, new ExplainAnalyzer(), $this->outputDir, new AIQueryAdvisor('')))->queryContext('zz_missing_table.sql', []);
            $this->fail('EXPLAIN on a missing table must throw');
        } catch (PDOException $e) {
            $this->assertStringContainsString('no_such_table', $e->getMessage(), 'the EXPLAIN error wins over the restore error');
        }
    }

    public function testARestoreFailureAfterASuccessfulExplainPropagates(): void
    {
        $pdo = new RestoreFailingPdo($this->connectionSettings());
        $analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), __DIR__ . '/sql', new AIQueryAdvisor(''));

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage(RestoreFailingPdo::MESSAGE);

        $analyzer->queryContext('1_full_table_scan.sql', ['min_views' => 1000]);
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

        $this->assertNotSame($result['cost'], $result['optimizer_comparison']['without_optimizer']['cost']);
        $this->assertSame($result['cost'], (float) $context->explain['query_block']['cost_info']['query_cost']);
        $this->assertStringContainsString("'example@example.com'", $context->sql);
        $this->assertStringStartsWith('-> ', (string) $context->explainAnalyze);
    }

    public function testAnalyzeSqlDirectoryWritesReportPerQueryAndSummary(): void
    {
        $this->outputDir = sys_get_temp_dir() . '/' . uniqid('sqlquality_report_', true);
        mkdir($this->outputDir);

        $this->createAnalyzer()->analyzeSqlDirectory(['1_full_table_scan.sql' => ['min_views' => 1000]], $this->outputDir);

        $this->assertTrue(file_exists($this->outputDir . '/1_full_table_scan.md'));
        $this->assertTrue(file_exists($this->outputDir . '/summary_report.md'));
    }
}

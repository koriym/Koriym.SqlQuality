<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;
use PHPUnit\Framework\TestCase;

use function file_put_contents;
use function is_dir;
use function is_file;
use function mkdir;
use function rmdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class SqlFileAnalyzerSafetyTest extends TestCase
{
    private string $tempSqlDir = '';

    protected function tearDown(): void
    {
        if ($this->tempSqlDir === '') {
            return;
        }

        $ddl = $this->tempSqlDir . '/ddl.sql';
        if (is_file($ddl)) {
            unlink($ddl);
        }

        if (is_dir($this->tempSqlDir)) {
            rmdir($this->tempSqlDir);
        }
    }

    public function testGetExecutedTimeRejectsDmlBeforeExecution(): void
    {
        $analyzer = new SqlFileAnalyzer(
            new PDO('sqlite::memory:'),
            new ExplainAnalyzer(),
            __DIR__ . '/sql',
            new AIQueryAdvisor(''),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Execution timing is limited to read-only SELECT statements');

        $analyzer->getExecutedTime('UPDATE users SET id = id + 1', []);
    }

    public function testAnalyzeRejectsNonExplainableStatementBeforeExecution(): void
    {
        $this->tempSqlDir = sys_get_temp_dir() . '/' . uniqid('sqlquality_safety_', true);
        mkdir($this->tempSqlDir);
        file_put_contents($this->tempSqlDir . '/ddl.sql', 'CREATE TABLE users (id INT PRIMARY KEY)');

        $analyzer = new SqlFileAnalyzer(
            new PDO('sqlite::memory:'),
            new ExplainAnalyzer(),
            $this->tempSqlDir,
            new AIQueryAdvisor(''),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXPLAIN FORMAT=JSON is limited to SELECT and DML statements');

        $analyzer->analyze('ddl.sql', [], $this->tempSqlDir, []);
    }
}

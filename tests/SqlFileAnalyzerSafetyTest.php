<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;
use PHPUnit\Framework\TestCase;

final class SqlFileAnalyzerSafetyTest extends TestCase
{
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
}

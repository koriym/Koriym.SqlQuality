<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use JsonSchema\Validator;

use function array_diff;
use function array_keys;
use function array_merge;
use function dirname;
use function fclose;
use function file_get_contents;
use function implode;
use function json_decode;
use function json_encode;
use function proc_close;
use function proc_open;
use function sort;
use function sprintf;
use function stream_get_contents;

use const PHP_BINARY;

final class JsonSchemaTest extends MySqlTestCase
{
    private const SCHEMA_DIR = __DIR__ . '/../schema';

    public function testSchemaFilesAreDraft202012(): void
    {
        foreach (['analyze-report.schema.json', 'explain-report.schema.json'] as $file) {
            $schema = json_decode((string) file_get_contents(self::SCHEMA_DIR . '/' . $file));

            $this->assertIsObject($schema, $file . ' must parse as a JSON object');
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema->{'$schema'}, $file . ' must declare draft 2020-12');
        }
    }

    public function testWarningTypeEnumMatchesDefaultMessages(): void
    {
        $expected = array_keys(ExplainAnalyzer::DEFAULT_MESSAGES);
        sort($expected);

        foreach (['analyze-report.schema.json', 'explain-report.schema.json'] as $file) {
            $schema = json_decode((string) file_get_contents(self::SCHEMA_DIR . '/' . $file), true);
            $actual = $schema['$defs']['issue']['properties']['type']['enum'];
            sort($actual);

            $message = sprintf(
                '%s: $defs.issue.properties.type.enum is out of sync with ExplainAnalyzer::DEFAULT_MESSAGES. Missing from schema: [%s]. Extra in schema: [%s].',
                $file,
                implode(', ', array_diff($expected, $actual)),
                implode(', ', array_diff($actual, $expected)),
            );
            $this->assertSame($expected, $actual, $message);
        }
    }

    public function testFakeAnalysisRunReportValidatesAgainstAnalyzeSchema(): void
    {
        $report = (new JsonReportGenerator())->generate(FakeAnalysisRun::of(
            [
                '1_full_table_scan.sql' => FakeAnalysisRun::result(497.95, [
                    FakeAnalysisRun::issue('FullTableScan', 'Critical'),
                ]),
            ],
            ['14_not_found.sql' => 'SQL file not found: /tmp/14_not_found.sql'],
        ));

        $this->assertValidJsonAgainstSchema((string) json_encode($report), 'analyze-report.schema.json');
    }

    public function testAnalyzeCliOutputValidatesAgainstSchema(): void
    {
        $this->connect();

        [$exitCode, $stdout] = $this->runCli([
            'analyze',
            '--sql-dir=' . __DIR__ . '/sql',
            '--params=' . __DIR__ . '/params/sql_params.php',
            '--format=json',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertValidJsonAgainstSchema($stdout, 'analyze-report.schema.json');
    }

    public function testExplainCliOutputValidatesAgainstSchema(): void
    {
        $this->connect();

        [$exitCode, $stdout] = $this->runCli([
            'explain',
            '--sql-file=' . __DIR__ . '/sql/1_full_table_scan.sql',
            '--params={"min_views":1000}',
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertValidJsonAgainstSchema($stdout, 'explain-report.schema.json');
    }

    private function assertValidJsonAgainstSchema(string $json, string $schemaFile): void
    {
        $schema = json_decode((string) file_get_contents(self::SCHEMA_DIR . '/' . $schemaFile));
        $data = json_decode($json);

        $validator = new Validator();
        $validator->validate($data, $schema);

        $errors = [];
        foreach ($validator->getErrors() as $error) {
            $errors[] = "{$error['property']}: {$error['message']}";
        }

        $this->assertTrue($validator->isValid(), $schemaFile . ":\n" . implode("\n", $errors));
    }

    /**
     * @param list<string> $args
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private function runCli(array $args): array
    {
        $command = array_merge([PHP_BINARY, dirname(__DIR__) . '/bin/sql-quality'], $args);

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

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use JsonException;
use Koriym\SqlQuality\Exception\InvalidSeverityThreshold;
use Koriym\SqlQuality\Exception\RuntimeException;
use Koriym\SqlQuality\Exception\UsageError;
use PDO;
use PDOException;
use stdClass;
use Throwable;

use function array_column;
use function array_diff;
use function array_intersect;
use function array_key_exists;
use function array_slice;
use function array_values;
use function basename;
use function count;
use function file_exists;
use function file_get_contents;
use function fwrite;
use function getenv;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function preg_match;
use function round;
use function sort;
use function str_ends_with;
use function str_starts_with;
use function stream_get_contents;
use function trim;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_EOL;

/**
 * The sql-quality command line. stdout carries the JSON report and nothing else; diagnostics go to stderr.
 *
 * Exit codes: 0 the check passed, 1 the check failed (an issue reached --fail-on, a rewrite is not
 * equivalent, an index is not effective), 2 the command could not run.
 *
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type AnalysisRun from Types
 * @psalm-import-type SqlParams from Types
 * @psalm-import-type DetectedWarning from Types
 */
final class Cli
{
    public const VERSION = '0.5.0';
    private const DEFAULT_DSN = 'mysql:host=127.0.0.1;dbname=test';
    private const CONNECTION_OPTIONS = ['dsn', 'user', 'password'];

    private const COMMANDS = [
        'analyze' => ['sql-dir', 'params', 'baseline', 'fail-on'],
        'explain' => ['sql-file', 'sql', 'params', 'detail', 'fail-on'],
        'compare' => ['before', 'before-sql', 'after', 'after-sql', 'params', 'before-params', 'after-params'],
        'try-index' => ['sql-file', 'sql', 'index', 'params', 'min-improvement', 'keep'],
    ];

    public const USAGE = <<<'USAGE'
sql-quality - MySQL query analysis for coding agents and CI

Usage:
  sql-quality analyze   --sql-dir=DIR [--params=FILE] [--baseline=FILE] [--fail-on=LEVEL]
  sql-quality explain   (--sql-file=FILE | --sql=SQL) [--params=JSON|FILE] [--detail=summary|full] [--fail-on=LEVEL]
  sql-quality compare   (--before=FILE | --before-sql=SQL) (--after=FILE | --after-sql=SQL) [--params=JSON|FILE]
                        [--before-params=JSON|FILE] [--after-params=JSON|FILE]
  sql-quality try-index (--sql-file=FILE | --sql=SQL) --index=DEFINITION [--params=JSON|FILE]
                        [--min-improvement=PERCENT] [--keep]
  sql-quality --help | --version

Commands:
  analyze    Analyze every *.sql file of a directory
  explain    Analyze one statement; --sql-file=- reads it from stdin
  compare    Check that a rewritten SELECT returns the same rows, and compare cost and issues
  try-index  Measure a statement with a candidate index added INVISIBLE (MySQL 8.0+), then drop it

Options:
  --params=FILE|JSON       A PHP or .json file, or an inline JSON object. analyze takes a file keyed by
                           SQL file name; the other commands also take one flat object of parameters
  --baseline=FILE          An earlier analyze report; adds baseline.new/resolved/persisting/unverified,
                           and --fail-on then counts only the new issues
  --fail-on=LEVEL          Exit 1 when an issue of critical, warning or info level or above is found
  --detail=LEVEL           summary (default) or full, which adds the EXPLAIN, schema and optimizer trace
  --index=DEFINITION       "CREATE INDEX name ON table (col, ...)" (a suggestion's ddl) or "table(col, ...)"
  --min-improvement=PCT    Cost reduction an index must reach to be effective (default: 5)
  --keep                   Keep an effective index (made visible) instead of dropping it
  --dsn=DSN                Database DSN (default: $SQL_QUALITY_DSN or mysql:host=127.0.0.1;dbname=test)
  --user=USER              Database user (default: $SQL_QUALITY_USER or root)
  --password=PASS          Database password (default: $SQL_QUALITY_PASSWORD or empty)

Exit codes:
  0  The check passed
  1  An issue reached --fail-on, a rewrite is not equivalent, or an index is not effective
  2  The command could not run: usage, connection, parameters, or a statement MySQL rejects

USAGE;

    /**
     * @param resource $stdout
     * @param resource $stderr
     * @param resource $stdin
     */
    public function __construct(
        private readonly mixed $stdout,
        private readonly mixed $stderr,
        private readonly mixed $stdin,
    ) {
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        if (count($argv) < 2 || in_array('--help', $argv, true) || in_array('-h', $argv, true)) {
            fwrite($this->stdout, self::USAGE);

            return 0;
        }

        if (in_array('--version', $argv, true)) {
            fwrite($this->stdout, 'sql-quality ' . self::VERSION . PHP_EOL);

            return 0;
        }

        $command = $argv[1];
        if (! array_key_exists($command, self::COMMANDS)) {
            return $this->fail("Unknown command: {$command}. Run 'sql-quality --help' for usage.");
        }

        try {
            $options = $this->parseOptions($command, array_slice($argv, 2));

            return match ($command) {
                'analyze' => $this->analyze($options),
                'explain' => $this->explain($options),
                'compare' => $this->compare($options),
                default => $this->tryIndex($options),
            };
        } catch (InvalidSeverityThreshold $e) {
            return $this->fail("--fail-on accepts critical, warning or info, not: {$e->getMessage()}");
        } catch (UsageError $e) {
            return $this->fail($e->getMessage());
        } catch (PDOException $e) {
            return $this->fail('PDOException: ' . $e->getMessage());
        } catch (RuntimeException $e) {
            return $this->fail(SqlFileAnalyzer::exceptionLabel($e) . ': ' . $e->getMessage());
        }
    }

    /**
     * @param list<string> $args
     *
     * @return array<string, string|false> false for a flag given without a value
     */
    private function parseOptions(string $command, array $args): array
    {
        $accepted = [...self::COMMANDS[$command], ...self::CONNECTION_OPTIONS];
        $options = [];
        foreach ($args as $arg) {
            if (! preg_match('/^--([a-z-]+)(?:=(.*))?$/s', $arg, $matches)) {
                throw new UsageError("Unexpected argument: {$arg}");
            }

            if (! in_array($matches[1], $accepted, true)) {
                throw new UsageError("{$command} does not accept --{$matches[1]}. Run 'sql-quality --help' for usage.");
            }

            $options[$matches[1]] = array_key_exists(2, $matches) ? $matches[2] : false;
        }

        return $options;
    }

    /** @param array<string, string|false> $options */
    private function analyze(array $options): int
    {
        $sqlDir = $this->stringOption($options, 'sql-dir');
        if ($sqlDir === null) {
            throw new UsageError('--sql-dir is required.');
        }

        if (! is_dir($sqlDir)) {
            throw new UsageError("SQL directory not found: {$sqlDir}");
        }

        $sqlParams = $this->sqlParams($this->stringOption($options, 'params'));
        $baselineFile = $this->stringOption($options, 'baseline');
        $baseline = $baselineFile === null ? null : Baseline::fromFile($baselineFile);
        $threshold = $this->threshold($options);
        $analyzer = new SqlFileAnalyzer($this->connect($options), new ExplainAnalyzer(), $sqlDir);

        $run = $analyzer->analyzeDirectory($sqlParams);
        $diff = $baseline?->diff($run);
        $failed = false;
        if ($threshold !== null) {
            $failed = $diff === null ? $threshold->isMetBy($run) : $threshold->isMetBySeverities(array_column($diff['new'], 'severity'));
        }

        return $this->writeReport((new JsonReportGenerator())->generate($run, $diff), $failed ? 1 : 0);
    }

    /** @param array<string, string|false> $options */
    private function explain(array $options): int
    {
        $detail = $this->stringOption($options, 'detail') ?? 'summary';
        if (! in_array($detail, ['summary', 'full'], true)) {
            throw new UsageError("--detail accepts summary or full, not: {$detail}");
        }

        [$file, $sql] = $this->statement($options, 'sql-file', 'sql');
        $params = $this->params($this->stringOption($options, 'params'), $file);
        $threshold = $this->threshold($options);
        $analyzer = new SqlFileAnalyzer($this->connect($options));

        ['result' => $result, 'context' => $context] = $analyzer->explainSql($sql, $params);

        $report = ['file' => $file, 'sql' => $context->sql, ...JsonReportGenerator::query($result)];
        if ($detail === 'full') {
            // context.schema is keyed by table name; a table-free query leaves it empty, and json_encode()
            // would emit [] there instead of the {} the schema requires.
            $contextData = $context->toArray();
            $contextData['schema'] = $contextData['schema'] === [] ? new stdClass() : $contextData['schema'];
            $report['context'] = $contextData;
        }

        $failed = $threshold !== null && $threshold->isMetBy(['results' => [(string) $file => $result], 'skipped' => []]);

        return $this->writeReport($report, $failed ? 1 : 0);
    }

    /** @param array<string, string|false> $options */
    private function compare(array $options): int
    {
        [$beforeFile, $beforeSql] = $this->statement($options, 'before', 'before-sql');
        [$afterFile, $afterSql] = $this->statement($options, 'after', 'after-sql');
        $shared = $this->stringOption($options, 'params');
        $beforeParams = $this->params($this->stringOption($options, 'before-params') ?? $shared, $beforeFile);
        $afterParams = $this->params($this->stringOption($options, 'after-params') ?? $shared, $afterFile);
        $pdo = $this->connect($options);
        $analyzer = new SqlFileAnalyzer($pdo);

        $comparison = (new RewriteComparator($pdo, $analyzer))->compare($beforeSql, $beforeParams, $afterSql, $afterParams);
        $before = $comparison['before'];
        $after = $comparison['after'];

        $report = [
            'equivalent' => $comparison['equivalent'],
            'same_order' => $comparison['same_order'],
            'same_columns' => $comparison['same_columns'],
            // equivalence over two empty result sets says nothing about the rewrite; choose parameters that return rows
            'both_empty' => $before['rows']->rowCount === 0 && $after['rows']->rowCount === 0,
            'before' => self::measurement($beforeFile, $before['analysis'], $before['rows']),
            'after' => self::measurement($afterFile, $after['analysis'], $after['rows']),
            'cost_change_percent' => IndexTrial::changePercent($before['analysis']['cost'], $after['analysis']['cost']),
            'time_change_percent' => self::timeChangePercent($before['analysis'], $after['analysis']),
            'issues' => self::issueDiff($before['analysis']['issues'], $after['analysis']['issues']),
        ];

        return $this->writeReport($report, $comparison['equivalent'] ? 0 : 1);
    }

    /** @param array<string, string|false> $options */
    private function tryIndex(array $options): int
    {
        [$file, $sql] = $this->statement($options, 'sql-file', 'sql');
        $definition = $this->stringOption($options, 'index');
        if ($definition === null) {
            throw new UsageError('--index is required: "CREATE INDEX name ON table (col, ...)" or "table(col, ...)".');
        }

        $index = IndexDefinition::parse($definition);
        $minImprovement = $this->stringOption($options, 'min-improvement') ?? (string) IndexTrial::DEFAULT_MIN_IMPROVEMENT;
        if (! is_numeric($minImprovement)) {
            throw new UsageError("--min-improvement must be a number, not: {$minImprovement}");
        }

        $params = $this->params($this->stringOption($options, 'params'), $file);
        $pdo = $this->connect($options);
        $trial = (new IndexTrial($pdo, new SqlFileAnalyzer($pdo)))->run($sql, $params, $index, array_key_exists('keep', $options), (float) $minImprovement);

        $report = [
            'file' => $file,
            'index' => [
                'table' => $index->table,
                'name' => $index->name,
                'columns' => $index->columnNames(),
                'ddl' => $index->createStatement(),
            ],
            'used' => $trial['used'],
            'effective' => $trial['effective'],
            'kept' => $trial['kept'],
            'min_improvement_percent' => (float) $minImprovement,
            'before' => self::measurement(null, $trial['before'], null),
            'after' => self::measurement(null, $trial['after'], null),
            'cost_change_percent' => IndexTrial::changePercent($trial['before']['cost'], $trial['after']['cost']),
            'time_change_percent' => self::timeChangePercent($trial['before'], $trial['after']),
            'issues' => self::issueDiff($trial['before']['issues'], $trial['after']['issues']),
        ];
        unset($report['before']['file'], $report['after']['file']);

        return $this->writeReport($report, $trial['effective'] ? 0 : 1);
    }

    /**
     * @param AnalysisResult $analysis
     *
     * @return array<string, mixed>
     */
    private static function measurement(string|null $file, array $analysis, ResultFingerprint|null $rows): array
    {
        $measurement = ['file' => $file];
        if ($rows !== null) {
            $measurement += [
                'columns' => $rows->columns,
                'row_count' => $rows->rowCount,
                'checksum' => $rows->unorderedChecksum,
                'ordered_checksum' => $rows->orderedChecksum,
            ];
        }

        return $measurement + [
            'cost' => $analysis['cost'],
            'execution_time_ms' => $analysis['execution_time'] === null ? null : round($analysis['execution_time'] * 1000, 3),
            'issues' => array_column($analysis['issues'], 'id'),
        ];
    }

    /**
     * @param AnalysisResult $before
     * @param AnalysisResult $after
     */
    private static function timeChangePercent(array $before, array $after): float|null
    {
        if ($before['execution_time'] === null || $after['execution_time'] === null) {
            return null;
        }

        return IndexTrial::changePercent($before['execution_time'], $after['execution_time']);
    }

    /**
     * @param list<DetectedWarning> $before
     * @param list<DetectedWarning> $after
     *
     * @return array{resolved: list<string>, new: list<string>, persisting: list<string>}
     */
    private static function issueDiff(array $before, array $after): array
    {
        $beforeIds = array_column($before, 'id');
        $afterIds = array_column($after, 'id');
        $resolved = array_values(array_diff($beforeIds, $afterIds));
        $new = array_values(array_diff($afterIds, $beforeIds));
        $persisting = array_values(array_intersect($beforeIds, $afterIds));
        sort($resolved);
        sort($new);
        sort($persisting);

        return ['resolved' => $resolved, 'new' => $new, 'persisting' => $persisting];
    }

    /**
     * The statement of --FILE (a path, or - for stdin) or --SQL, and the file name to report.
     *
     * @param array<string, string|false> $options
     *
     * @return array{0: string|null, 1: string}
     */
    private function statement(array $options, string $fileOption, string $sqlOption): array
    {
        $file = $this->stringOption($options, $fileOption);
        $sql = $this->stringOption($options, $sqlOption);
        if (($file === null) === ($sql === null)) {
            throw new UsageError("Give exactly one of --{$fileOption}=FILE and --{$sqlOption}=SQL.");
        }

        if ($sql !== null) {
            return [null, $sql];
        }

        /** @var string $file */
        if ($file === '-') {
            return [null, (string) stream_get_contents($this->stdin)];
        }

        if (! is_file($file)) {
            throw new UsageError("SQL file not found: {$file}");
        }

        return [basename($file), (string) file_get_contents($file)];
    }

    /**
     * Parameters of one statement: an inline JSON object, or a PHP / .json file holding either one flat
     * object or a map keyed by SQL file name, from which $file's entry is taken.
     *
     * @return array<string, mixed>
     */
    private function params(string|null $value, string|null $file): array
    {
        if ($value === null) {
            return [];
        }

        $decoded = str_starts_with(trim($value), '{') ? $this->decodeJsonObject($value, '--params') : $this->loadParamsFile($value);
        if (self::isKeyedBySqlFile($decoded)) {
            /** @var array<string, array<string, mixed>> $decoded */
            return $file === null ? [] : ($decoded[$file] ?? []);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return SqlParams */
    private function sqlParams(string|null $paramsFile): array
    {
        if ($paramsFile === null) {
            return [];
        }

        $decoded = $this->loadParamsFile($paramsFile);
        foreach ($decoded as $sqlFile => $params) {
            if (! is_string($sqlFile) || ! is_array($params)) {
                throw new UsageError("Params file entry for '{$sqlFile}' must map a SQL file name to an object of parameters: {$paramsFile}");
            }
        }

        /** @var SqlParams $decoded */
        return $decoded;
    }

    /** @return array<array-key, mixed> */
    private function loadParamsFile(string $path): array
    {
        if (! file_exists($path)) {
            throw new UsageError("Params file not found: {$path}");
        }

        if (str_ends_with($path, '.json')) {
            return $this->decodeJsonObject((string) file_get_contents($path), $path);
        }

        try {
            /** @psalm-suppress UnresolvableInclude */
            $decoded = require $path;
        } catch (Throwable $e) {
            throw new UsageError("Params file could not be loaded: {$path}: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new UsageError("Params file must return an array: {$path}");
        }

        return $decoded;
    }

    /** @return array<array-key, mixed> */
    private function decodeJsonObject(string $json, string $source): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UsageError("{$source} is not valid JSON: {$e->getMessage()}");
        }

        if (! is_array($decoded)) {
            throw new UsageError("{$source} must be a JSON object");
        }

        return $decoded;
    }

    /** @param array<array-key, mixed> $decoded */
    private static function isKeyedBySqlFile(array $decoded): bool
    {
        if ($decoded === []) {
            return false;
        }

        foreach ($decoded as $key => $value) {
            if (! is_string($key) || ! str_ends_with($key, '.sql') || ! is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, string|false> $options */
    private function threshold(array $options): SeverityThreshold|null
    {
        if (! array_key_exists('fail-on', $options)) {
            return null;
        }

        $failOn = $options['fail-on'];

        return new SeverityThreshold(is_string($failOn) ? $failOn : '');
    }

    /** @param array<string, string|false> $options */
    private function stringOption(array $options, string $name): string|null
    {
        if (! array_key_exists($name, $options)) {
            return null;
        }

        $value = $options[$name];
        if ($value === false) {
            throw new UsageError("--{$name} requires a value.");
        }

        return $value;
    }

    /** @param array<string, string|false> $options */
    private function connect(array $options): PDO
    {
        $dsn = $this->stringOption($options, 'dsn') ?? self::env('SQL_QUALITY_DSN') ?? self::DEFAULT_DSN;
        $user = $this->stringOption($options, 'user') ?? self::env('SQL_QUALITY_USER') ?? 'root';
        $password = $this->stringOption($options, 'password') ?? self::env('SQL_QUALITY_PASSWORD') ?? '';

        try {
            return new PDO($dsn, $user, $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new UsageError("Database connection failed: {$e->getMessage()}");
        }
    }

    private static function env(string $name): string|null
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** json_encode() fails on malformed UTF-8 in the report (the SQL text can carry it); that must not pass as a successful empty run. */
    private function writeReport(mixed $report, int $exitCode): int
    {
        try {
            $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->fail("Report could not be encoded as JSON: {$e->getMessage()}");
        }

        fwrite($this->stdout, $json . PHP_EOL);

        return $exitCode;
    }

    private function fail(string $message): int
    {
        fwrite($this->stderr, 'Error: ' . $message . PHP_EOL);

        return 2;
    }
}

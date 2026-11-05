<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\InvalidExplainResult;
use Koriym\SqlQuality\Exception\NotExplainable;
use Koriym\SqlQuality\Exception\NotReadOnlySelect;
use Koriym\SqlQuality\Exception\QueryFailed;
use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;
use PDOException;
use Throwable;

use function array_column;
use function array_diff;
use function array_keys;
use function array_merge;
use function array_pop;
use function array_shift;
use function array_sum;
use function array_unique;
use function array_values;
use function basename;
use function count;
use function file_exists;
use function file_get_contents;
use function glob;
use function is_array;
use function is_string;
use function json_decode;
use function microtime;
use function natsort;
use function sort;
use function strrpos;
use function substr;
use function var_export;

/**
 * Analyzes SQL statements with EXPLAIN FORMAT=JSON and EXPLAIN ANALYZE, once with the default
 * optimizer_switch and once with the optimizer features turned off.
 *
 * @psalm-import-type SqlParams from Types
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type AnalysisRun from Types
 * @psalm-import-type ShowWarning from Types
 * @psalm-import-type OptimizerTraceExcerpt from Types
 * @psalm-import-type DetectedWarning from Types
 */
final class SqlFileAnalyzer
{
    private const TRIAL_COUNT = 10;
    private readonly OptimizerSettingsInterface $optimizerSettings;
    private readonly ReadOnlySession $readOnlySession;
    private readonly ParameterBinder $binder;
    private readonly SchemaReader $schemaReader;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ExplainAnalyzer $analyzer = new ExplainAnalyzer(),
        private readonly string $sqlDir = '.',
        OptimizerSettingsInterface|null $optimizerSettings = null,
    ) {
        $this->optimizerSettings = $optimizerSettings ?? new OptimizerSettings($pdo);
        $this->readOnlySession = new ReadOnlySession($pdo);
        $this->binder = new ParameterBinder($pdo);
        $this->schemaReader = new SchemaReader($pdo);
    }

    /**
     * Read once per public entry point, before the EXPLAIN: a caller-owned connection can change
     * sql_mode between calls, and a SELECT after the EXPLAIN would replace SHOW WARNINGS and the
     * optimizer trace. null when the connection cannot report sql_mode (another driver, or an
     * error mode returning false); the classifier then rejects under every reading.
     */
    public function quoteRules(): QuoteRules|null
    {
        try {
            $statement = $this->pdo->query('SELECT @@session.sql_mode');
        } catch (PDOException) {
            return null;
        }

        if ($statement === false) {
            return null;
        }

        $mode = $statement->fetchColumn();

        return is_string($mode) ? QuoteRules::fromSqlMode($mode) : null;
    }

    /**
     * File names of the *.sql files in the SQL directory, in natural order.
     *
     * @return list<string>
     */
    public function sqlFiles(): array
    {
        $files = [];
        foreach (glob($this->sqlDir . '/*.sql') ?: [] as $path) {
            $files[] = basename($path);
        }

        natsort($files);

        return array_values($files);
    }

    /**
     * Analyzes every *.sql file of the SQL directory, and every file $sqlParams names.
     * A file with no entry in $sqlParams is analyzed without parameters.
     *
     * @param SqlParams $sqlParams
     *
     * @return AnalysisRun
     */
    public function analyzeDirectory(array $sqlParams = []): array
    {
        $files = array_values(array_unique(array_merge($this->sqlFiles(), array_keys($sqlParams))));
        $params = [];
        foreach ($files as $file) {
            $params[$file] = $sqlParams[$file] ?? [];
        }

        return $this->analyzeSQLFiles($params);
    }

    /**
     * Analyzes the files $sqlParams names, each with its parameters. A file that cannot be
     * analyzed is listed in skipped with the exception's short class name and message.
     *
     * @param SqlParams $sqlParams
     *
     * @return AnalysisRun
     */
    public function analyzeSQLFiles(array $sqlParams): array
    {
        $results = [];
        $skipped = [];
        foreach ($sqlParams as $sqlFile => $params) {
            try {
                $results[$sqlFile] = $this->analyze($sqlFile, $params);
            } catch (\RuntimeException $e) {
                $skipped[$sqlFile] = self::exceptionLabel($e) . ': ' . $e->getMessage();
            }
        }

        return ['results' => $results, 'skipped' => $skipped];
    }

    /** short class name, so a skipped-file reason or stderr line reads e.g. "NotExplainable: …" rather than a bare message */
    public static function exceptionLabel(Throwable $e): string
    {
        $class = $e::class;
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }

    /** @throws RuntimeException */
    public function readSqlFile(string $filename): string
    {
        $path = $this->sqlDir . '/' . $filename;
        if (! file_exists($path)) {
            throw new RuntimeException("SQL file not found: {$path}");
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException("Failed to read SQL file: {$path}");
        }

        return $content;
    }

    /**
     * The QueryContext the detectors receive for the default optimizer_switch.
     *
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException
     */
    public function queryContext(string $sqlFile, array $params): QueryContext
    {
        return $this->buildQueryContext($this->readSqlFile($sqlFile), $params, $this->quoteRules(), withOptimizerTrace: true);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return AnalysisResult
     *
     * @throws RuntimeException
     */
    public function analyze(string $sqlFile, array $params): array
    {
        return $this->explain($sqlFile, $params)['result'];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return AnalysisResult
     *
     * @throws RuntimeException
     */
    public function analyzeSql(string $sql, array $params): array
    {
        return $this->explainSql($sql, $params)['result'];
    }

    /**
     * Analyzes one SQL file and returns the QueryContext built for the optimizer-enabled pass alongside
     * the result, so a caller that needs both does not trigger a third EXPLAIN / EXPLAIN ANALYZE round trip.
     *
     * @param array<string, mixed> $params
     *
     * @return array{result: AnalysisResult, context: QueryContext}
     *
     * @throws RuntimeException
     */
    public function explain(string $sqlFile, array $params): array
    {
        return $this->explainSql($this->readSqlFile($sqlFile), $params);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{result: AnalysisResult, context: QueryContext}
     *
     * @throws RuntimeException
     */
    public function explainSql(string $sql, array $params): array
    {
        $quoteRules = $this->quoteRules();
        $context = $this->buildQueryContext($sql, $params, $quoteRules, withOptimizerTrace: true);
        $issues = $this->analyzer->analyze($context);
        $classification = (new SqlSafetyClassifier($quoteRules))->classify($sql);
        $executed = $classification['is_read_only_select'];
        $executionTime = $executed ? $this->timeExecution($context->sql) : null;
        $withoutOptimizer = $this->withoutOptimizer($sql, $params, $quoteRules);

        return [
            'result' => [
                'executed' => $executed,
                'skipped_reason' => $executed ? null : $classification['reason'],
                'issues' => $issues,
                'explain_result' => $context->explain,
                'cost' => ExplainAnalyzer::queryCost($context->explain),
                'execution_time' => $executionTime,
                'without_optimizer' => $withoutOptimizer,
            ],
            'context' => $context,
        ];
    }

    /**
     * Ids of the issues the no-optimizer plan has and the default plan does not: problems the optimizer
     * currently works around, which can come back when statistics or data volume change.
     *
     * @param AnalysisResult $result
     *
     * @return list<string>
     */
    public static function hiddenIssues(array $result): array
    {
        $ids = array_diff(
            array_column($result['without_optimizer']['issues'], 'id'),
            array_column($result['issues'], 'id'),
        );
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException
     */
    public function bind(string $sql, array $params): string
    {
        return $this->binder->bind($sql, $params, $this->quoteRules() ?? new QuoteRules());
    }

    /**
     * @param array<string, mixed> $params
     * @param QuoteRules|null      $quoteRules         the connection's quote rules, resolved by the caller before any EXPLAIN
     * @param bool                 $withOptimizerTrace capture information_schema.OPTIMIZER_TRACE for the EXPLAIN; only meaningful with the default optimizer_switch
     *
     * @throws RuntimeException
     */
    private function buildQueryContext(string $sql, array $params, QuoteRules|null $quoteRules, bool $withOptimizerTrace = false): QueryContext
    {
        $classifier = new SqlSafetyClassifier($quoteRules);
        $classification = $classifier->classify($sql);
        if (! $classification['is_explainable']) {
            throw new NotExplainable($classification['reason']);
        }

        $interpolatedSql = $this->binder->bind($sql, $params, $quoteRules ?? new QuoteRules());
        $savedTrace = $withOptimizerTrace ? $this->enableOptimizerTrace() : null;
        $original = null;
        try {
            $explain = $this->executeExplain($interpolatedSql);
            // SHOW WARNINGS covers the last statement only and, unlike any SELECT, leaves the optimizer trace in place;
            // EXPLAIN ANALYZE and the schema lookups would replace both
            $warnings = $this->getWarnings();
            $optimizerTrace = $savedTrace === null ? null : $this->readOptimizerTrace($explain);
        } catch (Throwable $e) {
            $original = $e;

            throw $e;
        } finally {
            if ($savedTrace !== null) {
                try {
                    if ($this->pdo->exec("SET optimizer_trace = '{$savedTrace}'") === false) {
                        throw new RuntimeException('Failed to restore optimizer_trace');
                    }
                } catch (Throwable $restoreError) {
                    // A failed restore must not mask the exception the EXPLAIN threw.
                    if ($original === null) {
                        throw $restoreError;
                    }
                }
            }
        }

        $explainAnalyze = $classifier->isReadOnlySelect($sql) ? $this->executeExplainAnalyze($interpolatedSql) : null;
        $schema = $this->schemaReader->forSql($sql);

        return new QueryContext($interpolatedSql, $explain, $explainAnalyze, $warnings, $schema, $optimizerTrace, $quoteRules ?? new QuoteRules());
    }

    /** @return string|null the optimizer_trace value to restore, null when the server has no optimizer trace to enable */
    private function enableOptimizerTrace(): string|null
    {
        try {
            $stmt = $this->pdo->query('SELECT @@optimizer_trace');
            if ($stmt === false) {
                return null;
            }

            /** @var string|false $saved */
            $saved = $stmt->fetchColumn();
            if ($saved === false || $this->pdo->exec("SET optimizer_trace = 'enabled=on'") === false) {
                return null;
            }
        } catch (PDOException) {
            return null;
        }

        return $saved;
    }

    /**
     * @param ExplainResult $explain
     *
     * @return OptimizerTraceExcerpt|null
     */
    private function readOptimizerTrace(array $explain): array|null
    {
        try {
            $stmt = $this->pdo->query('SELECT TRACE FROM information_schema.OPTIMIZER_TRACE');
        } catch (PDOException) {
            return null;
        }

        if ($stmt === false) {
            return null;
        }

        /** @var string|false $trace */
        $trace = $stmt->fetchColumn();
        if ($trace === false) {
            return null;
        }

        return OptimizerTrace::excerpt($trace, $explain);
    }

    /**
     * @return ExplainResult
     *
     * @throws RuntimeException
     */
    private function executeExplain(string $interpolatedSql): array
    {
        $stmt = $this->pdo->query('EXPLAIN FORMAT=JSON ' . $interpolatedSql);
        if ($stmt === false) {
            throw new RuntimeException('Failed to execute EXPLAIN query');
        }

        /** @var array|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result === false) {
            throw new RuntimeException('Failed to get EXPLAIN result');
        }

        $explainJson = $result['EXPLAIN'] ?? '';
        if ($explainJson === '') {
            throw new RuntimeException('Empty EXPLAIN result');
        }

        if (! is_string($explainJson)) {
            throw new InvalidExplainResult(var_export($explainJson, true));
        }

        $explainJsonData = json_decode($explainJson, true);
        if (! is_array($explainJsonData)) {
            throw new InvalidExplainResult($explainJson);
        }

        if (! isset($explainJsonData['query_block']) || ! is_array($explainJsonData['query_block'])) {
            throw new InvalidExplainResult($explainJson);
        }

        /** @var ExplainResult $explainJsonData */
        return $explainJsonData;
    }

    /** @throws RuntimeException */
    private function executeExplainAnalyze(string $interpolatedSql): string
    {
        /** @var array|false $analyzeResult */
        $analyzeResult = $this->readOnlySession->run(function () use ($interpolatedSql): mixed {
            $analyzeStmt = $this->pdo->query('EXPLAIN ANALYZE ' . $interpolatedSql);
            if ($analyzeStmt === false) {
                throw new RuntimeException('Failed to execute EXPLAIN ANALYZE query');
            }

            return $analyzeStmt->fetch(PDO::FETCH_NUM);
        });
        if ($analyzeResult === false || ! isset($analyzeResult[0])) {
            throw new RuntimeException('Failed to get EXPLAIN ANALYZE result');
        }

        return (string) $analyzeResult[0];
    }

    /**
     * @return list<ShowWarning>
     *
     * @throws RuntimeException
     */
    private function getWarnings(): array
    {
        $stmt = $this->pdo->query('SHOW WARNINGS');
        if ($stmt === false) {
            throw new RuntimeException('Failed to execute SHOW WARNINGS');
        }

        /** @var list<ShowWarning> $warnings */
        $warnings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $warnings;
    }

    /**
     * Cost and issues of the plan with the optimizer features turned off. The statement is not timed here.
     *
     * @param array<string, mixed> $params
     *
     * @return array{cost: float, issues: list<DetectedWarning>}
     *
     * @throws RuntimeException
     */
    private function withoutOptimizer(string $sql, array $params, QuoteRules|null $quoteRules): array
    {
        $savedSettings = $this->optimizerSettings->saveCurrentSettings();
        $original = null;
        try {
            $this->optimizerSettings->disableAll();
            $context = $this->buildQueryContext($sql, $params, $quoteRules);

            return [
                'cost' => ExplainAnalyzer::queryCost($context->explain),
                'issues' => $this->analyzer->analyze($context),
            ];
        } catch (Throwable $e) {
            $original = $e;

            throw $e;
        } finally {
            try {
                $this->optimizerSettings->restore($savedSettings);
            } catch (Throwable $restoreError) {
                // A failed restore must not mask the exception the analysis threw.
                if ($original === null) {
                    throw $restoreError;
                }
            }
        }
    }

    /**
     * Average wall-clock seconds of a read-only SELECT, run in a read-only session.
     *
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException
     */
    public function getExecutedTime(string $sql, array $params): float
    {
        if (! (new SqlSafetyClassifier($this->quoteRules()))->isReadOnlySelect($sql)) {
            throw new NotReadOnlySelect($sql);
        }

        return $this->timeExecution($this->bind($sql, $params));
    }

    private function timeExecution(string $interpolatedSql): float
    {
        return $this->readOnlySession->run(fn (): float => $this->measureExecution($interpolatedSql));
    }

    private function measureExecution(string $interpolatedSql): float
    {
        // two warm-up runs fill the buffer pool before anything is timed
        for ($i = 0; $i < 2; $i++) {
            $this->fetchAll($interpolatedSql);
        }

        $executionTimes = [];
        for ($i = 0; $i < self::TRIAL_COUNT; $i++) {
            $startTime = microtime(true);
            // the whole result set is fetched, so the time covers transferring it
            $this->fetchAll($interpolatedSql);
            $executionTimes[] = microtime(true) - $startTime;
        }

        sort($executionTimes);
        array_shift($executionTimes); // Remove the minimum value
        array_pop($executionTimes);   // Remove the maximum value

        return array_sum($executionTimes) / (float) count($executionTimes);
    }

    /** @throws QueryFailed */
    private function fetchAll(string $interpolatedSql): void
    {
        $stmt = $this->pdo->query($interpolatedSql);
        if ($stmt === false) {
            throw new QueryFailed($interpolatedSql);
        }

        $stmt->fetchAll();
    }
}

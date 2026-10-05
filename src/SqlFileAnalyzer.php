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

use function array_keys;
use function array_map;
use function array_pop;
use function array_shift;
use function array_sum;
use function array_values;
use function count;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function implode;
use function is_array;
use function is_bool;
use function is_dir;
use function is_null;
use function is_string;
use function json_decode;
use function microtime;
use function mkdir;
use function pathinfo;
use function preg_replace;
use function sort;
use function strrpos;
use function substr;
use function var_export;

use const PATHINFO_FILENAME;

/**
 * @psalm-import-type SqlParams from Types
 * @psalm-import-type ExplainResult from Types
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type AnalysisRun from Types
 * @psalm-import-type AnalysisWithSettingsResult from Types
 * @psalm-import-type SchemaInfo from Types
 * @psalm-import-type ShowWarning from Types
 * @psalm-import-type OptimizerTraceExcerpt from Types
 */
final class SqlFileAnalyzer
{
    private const TRIAL_COUNT = 10;
    private readonly OptimizerSettingsInterface $optimizerSettings;
    private readonly SqlSafetyClassifier $sqlSafetyClassifier;
    private readonly ReadOnlySession $readOnlySession;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ExplainAnalyzer $analyzer,
        private readonly string $sqlDir,
        private readonly AIQueryAdvisor $aiAdvisor,
        OptimizerSettingsInterface|null $optimizerSettings = null
    ) {
        $this->optimizerSettings = $optimizerSettings ?? new OptimizerSettings($pdo);
        $this->sqlSafetyClassifier = new SqlSafetyClassifier();
        $this->readOnlySession = new ReadOnlySession($pdo);
    }

    /**
     * Analyzes the SQL files contained in the specified parameters, generates statistical analyses,
     * outputs detailed Markdown reports for each SQL file, and creates a summary report.
     *
     * @param SqlParams $sqlParams An associative array of SQL parameters, such as file paths and configurations, to be analyzed.
     * @param string    $outputDir The directory where output reports, including individual Markdown files and a summary report, will be saved.
     *
     *               example: $sqlParams [
     *                  1_full_table_scan.sql' => ['min_views' => 1000],
     *                  2_filesort.sql' => ['status' => 'published', 'limit' => 10],
     *               ]
     *
     * @return array<string, array<string, mixed>> Returns an associative array where the keys are SQL file paths and the values are their respective analysis results, including AI suggestions and identified issues.
     */
    public function analyzeSqlDirectory(array $sqlParams, string $outputDir): array
    {
        $results = $this->analyzeSQLFiles($sqlParams)['results'];
        $this->saveReports($results, $outputDir);

        return $results;
    }

    /**
     * @param array<string, AnalysisResult> $results
     *
     * @throws RuntimeException
     */
    public function saveReports(array $results, string $outputDir): void
    {
        $statistics = new QueryStatisticsCalculator();
        $statistics->calculate($results);

        $classifier = new StatisticalQueryLevelClassifier();
        $reportGenerator = new MarkdownSummaryReportGenerator($statistics, $classifier);

        foreach ($results as $sqlFile => $analysisResult) {
            $this->savePromptToMarkdown($sqlFile, $analysisResult['ai_suggestions'], $outputDir);
        }

        $reportGenerator->saveSummaryReport($outputDir, 'summary_report.md');
    }

    /**
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

    /** @param ExplainResult $explainResult */
    private function calculateCost(array $explainResult): float
    {
        $cost = $this->analyzer->calculateQueryCost($explainResult);

        return $cost['total_cost'];
    }

    private function readSqlFile(string $filename): string
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
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException
     */
    public function queryContext(string $sqlFile, array $params): QueryContext
    {
        return $this->buildQueryContext($this->readSqlFile($sqlFile), $params, withOptimizerTrace: true);
    }

    /**
     * @param array<string, mixed> $params
     * @param bool                 $withOptimizerTrace capture information_schema.OPTIMIZER_TRACE for the EXPLAIN; only meaningful with the default optimizer_switch
     *
     * @throws RuntimeException
     */
    private function buildQueryContext(string $sql, array $params, bool $withOptimizerTrace = false): QueryContext
    {
        $classification = $this->sqlSafetyClassifier->classify($sql);
        if (! $classification['is_explainable']) {
            throw new NotExplainable($classification['reason']);
        }

        $interpolatedSql = $this->interpolateQuery($sql, $params);
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

        $explainAnalyze = $this->sqlSafetyClassifier->isReadOnlySelect($sql) ? $this->executeExplainAnalyze($interpolatedSql) : null;
        $schema = $this->getSchemaInfo($sql);

        return new QueryContext($interpolatedSql, $explain, $explainAnalyze, $warnings, $schema, $optimizerTrace);
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
        // FORMAT=JSON の EXPLAIN を実行
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

    /** @param array<string, mixed> $params */
    private function interpolateQuery(string $sql, array $params): string
    {
        $keys = array_map(
            static fn (string $key): string => "/:$key/",
            array_keys($params),
        );

        $values = array_map(
            function (mixed $value): string {
                if (is_array($value) && count($value) === 0) {
                    throw new RuntimeException('Empty list given');
                }

                return match (true) {
                    is_null($value) => 'NULL',
                    is_bool($value) => $value ? '1' : '0',
                    is_string($value) => $this->pdo->quote($value),
                    is_array($value) => implode(',', array_map(fn ($v) => is_string($v) ? $this->pdo->quote($v) : (string) $v, $value)),
                    default => (string) $value
                };
            },
            array_values($params),
        );

        return (string) preg_replace($keys, $values, $sql);
    }

    /** @return array<string, SchemaInfo> */
    private function getSchemaInfo(string $sql): array
    {
        $tableNames = $this->aiAdvisor->extractTableNames($sql);
        $schemaInfo = [];
        foreach ($tableNames as $tableName) {
            $schemaInfo[$tableName] = $this->aiAdvisor->extractSchemaInfo($this->pdo, $tableName);
        }

        return $schemaInfo;
    }

    /** @throws RuntimeException */
    private function savePromptToMarkdown(string $sqlFile, string $prompt, string $outputDir): void
    {
        if (! is_dir($outputDir) && ! mkdir($outputDir, 0777, true)) {
            throw new RuntimeException("Failed to create directory: {$outputDir}");
        }

        $promptFile = $outputDir . '/' . pathinfo($sqlFile, PATHINFO_FILENAME) . '.md';
        if (file_put_contents($promptFile, $prompt) === false) {
            throw new RuntimeException("Failed to save prompt to file: {$promptFile}");
        }
    }

    /** @param array<string, AnalysisResult> $results */
    public function generateSummaryReport(array $results, string $outputDir): string
    {
        $statistics = new QueryStatisticsCalculator();
        $classifier = new StatisticalQueryLevelClassifier();
        $reportGenerator = new MarkdownSummaryReportGenerator($statistics, $classifier);

        return $reportGenerator->generate($results);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return AnalysisResult
     */
    public function analyze(string $sqlFile, array $params): array
    {
        $sql = $this->readSqlFile($sqlFile);

        $defaultAnalysis = $this->analyzeContext($this->buildQueryContext($sql, $params, withOptimizerTrace: true), $sql, $params, $sqlFile);
        $noOptimizerAnalysis = $this->analyzeWithoutOptimizer($sql, $params, $sqlFile);

        return $this->combineOptimizerComparison($defaultAnalysis, $noOptimizerAnalysis);
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
        $sql = $this->readSqlFile($sqlFile);
        $context = $this->buildQueryContext($sql, $params, withOptimizerTrace: true);

        $defaultAnalysis = $this->analyzeContext($context, $sql, $params, $sqlFile);
        $noOptimizerAnalysis = $this->analyzeWithoutOptimizer($sql, $params, $sqlFile);

        return [
            'result' => $this->combineOptimizerComparison($defaultAnalysis, $noOptimizerAnalysis),
            'context' => $context,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return AnalysisWithSettingsResult
     */
    private function analyzeWithoutOptimizer(string $sql, array $params, string $sqlFile): array
    {
        $savedSettings = $this->optimizerSettings->saveCurrentSettings();
        try {
            $this->optimizerSettings->disableAll();

            return $this->analyzeContext($this->buildQueryContext($sql, $params), $sql, $params, $sqlFile);
        } finally {
            $this->optimizerSettings->restore($savedSettings);
        }
    }

    /**
     * @param AnalysisWithSettingsResult $defaultAnalysis
     * @param AnalysisWithSettingsResult $noOptimizerAnalysis
     *
     * @return AnalysisResult
     */
    private function combineOptimizerComparison(array $defaultAnalysis, array $noOptimizerAnalysis): array
    {
        $noOptimizerCost = (float) $noOptimizerAnalysis['cost'];
        $noOptimizerTime = (float) $noOptimizerAnalysis['execution_time'];

        return [
            ...$defaultAnalysis,
            'optimizer_comparison' => [
                'with_optimizer' => $defaultAnalysis,
                'without_optimizer' => $noOptimizerAnalysis,
                'difference' => [
                    'cost_percent' => $noOptimizerCost > 0.0 ? ((float) $defaultAnalysis['cost'] - $noOptimizerCost) / $noOptimizerCost * 100.0 : 0.0,
                    'time_percent' => $noOptimizerTime > 0.0 ? ((float) $defaultAnalysis['execution_time'] - $noOptimizerTime) / $noOptimizerTime * 100.0 : 0.0,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return AnalysisWithSettingsResult
     */
    private function analyzeContext(QueryContext $context, string $sql, array $params, string $sqlFile): array
    {
        $classification = $this->sqlSafetyClassifier->classify($sql);
        $executed = $classification['is_read_only_select'];
        $skippedReason = $executed ? null : $classification['reason'];
        $executionTime = $executed ? $this->getExecutedTime($sql, $params) : 0.0;
        $issues = $this->analyzer->analyze($context);
        $cost = $this->calculateCost($context->explain);

        $aiPrompt = $this->aiAdvisor->generatePrompt(
            $sqlFile,
            $sql,
            $context->explain,
            $context->explainAnalyze ?? 'N/A (EXPLAIN ANALYZE skipped: statement is not a read-only SELECT)',
            $context->warnings,
            $issues,
            $context->schema,
        );

        return [
            'mode' => 'wd',
            'executed' => $executed,
            'skipped_reason' => $skippedReason,
            'issues' => $issues,
            'explain_result' => $context->explain,
            'ai_suggestions' => $aiPrompt,
            'cost' => $cost,
            'execution_time' => $executionTime,
        ];
    }

    /** @param array<string, mixed> $params */
    public function getExecutedTime(string $sql, array $params): float
    {
        if (! $this->sqlSafetyClassifier->isReadOnlySelect($sql)) {
            throw new NotReadOnlySelect($sql);
        }

        $interpolatedSql = $this->interpolateQuery($sql, $params);

        return $this->readOnlySession->run(fn (): float => $this->measureExecution($interpolatedSql));
    }

    private function measureExecution(string $interpolatedSql): float
    {
        // warm up the cache
        $warmupStmt = $this->pdo->query($interpolatedSql);
        if ($warmupStmt === false) {
            throw new QueryFailed($interpolatedSql);
        }

        $warmupStmt->fetchAll();

        $secondWarmupStmt = $this->pdo->query($interpolatedSql);
        if ($secondWarmupStmt === false) {
            throw new QueryFailed($interpolatedSql);
        }

        $secondWarmupStmt->fetchAll();

        $executionTimes = [];
        for ($i = 0; $i < self::TRIAL_COUNT; $i++) {
            $startTime = microtime(true);
            $stmt = $this->pdo->query($interpolatedSql);
            if ($stmt === false) {
                throw new QueryFailed($interpolatedSql);
            }

            // Fetch all results to ensure:
            // 1. The query is fully executed
            // 2. The result set is fully retrieved
            // 3. The database cache is properly warmed up
            // This helps in getting consistent execution times across trials
            $stmt->fetchAll();
            $endTime = microtime(true);
            $executionTimes[] = $endTime - $startTime;
        }

        sort($executionTimes);
        array_shift($executionTimes); // Remove the minimum value
        array_pop($executionTimes);   // Remove the maximum value

        return array_sum($executionTimes) / (float) count($executionTimes);
    }
}

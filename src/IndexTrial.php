<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Closure;
use Koriym\SqlQuality\Exception\ActiveTransactionRejected;
use Koriym\SqlQuality\Exception\IndexAlreadyExists;
use Koriym\SqlQuality\Exception\IndexCleanupFailed;
use Koriym\SqlQuality\Exception\IndexTrialUnsupported;
use Koriym\SqlQuality\Exception\InvisibleIndexesPresent;
use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;
use Throwable;

use function array_merge;
use function array_unique;
use function array_values;
use function function_exists;
use function implode;
use function in_array;
use function is_array;
use function is_numeric;
use function is_string;
use function pcntl_sigprocmask;
use function preg_match;
use function preg_quote;
use function round;
use function stripos;
use function version_compare;

use const SIG_BLOCK;
use const SIG_UNBLOCK;
use const SIGINT;
use const SIGTERM;

/**
 * Measures a statement with and without a candidate index, without changing the plans of other sessions.
 *
 * The index is added INVISIBLE (MySQL 8.0+): the optimizer ignores it except in this session, where
 * optimizer_switch use_invisible_indexes=on is set for the measurement. Afterwards the index is dropped,
 * or, with $keep, made visible. Adding the index is still real DDL: it reads the whole table and needs
 * the ALTER privilege. ALGORITHM=INPLACE, LOCK=NONE keeps writes flowing while it builds, but the ALTER
 * and the DROP still wait for a metadata lock behind open transactions on the table, and statements
 * queue behind that wait; lock_wait_timeout is lowered for this session so the trial fails instead.
 * With $keep the index stays, visible, only when the trial is effective.
 *
 * A process killed during the trial can leave the INVISIBLE index behind; $notice receives the
 * statement that removes it before the index is added.
 *
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type ExplainResult from Types
 */
final class IndexTrial
{
    public const DEFAULT_MIN_IMPROVEMENT = 5.0;
    public const DEFAULT_LOCK_WAIT_TIMEOUT = 10;

    /** the analyzer runs on $pdo itself: use_invisible_indexes=on is set on that session only */
    private readonly SqlFileAnalyzer $analyzer;

    /** @param (Closure(string): void)|null $notice */
    public function __construct(
        private readonly PDO $pdo,
        private readonly Closure|null $notice = null,
        private readonly int $lockWaitTimeout = self::DEFAULT_LOCK_WAIT_TIMEOUT,
    ) {
        $this->analyzer = new SqlFileAnalyzer($pdo);
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{before: AnalysisResult, after: AnalysisResult, used: bool, effective: bool, kept: bool}
     *
     * @throws RuntimeException
     */
    public function run(string $sql, array $params, IndexDefinition $index, bool $keep = false, float $minImprovement = self::DEFAULT_MIN_IMPROVEMENT): array
    {
        // DDL commits an open transaction implicitly; the caller's pending writes would become permanent
        if ($this->pdo->inTransaction()) {
            throw new ActiveTransactionRejected();
        }

        $this->assertSupported();
        $this->assertAbsent($index);
        $this->assertNoInvisibleIndexes($sql, $index);
        // bind first: an unbound parameter fails here, before any DDL
        $this->analyzer->bind($sql, $params);

        $before = $this->analyzer->analyzeSql($sql, $params);
        $savedSwitch = $this->sessionVariable('optimizer_switch');
        $savedLockWait = $this->sessionVariable('lock_wait_timeout');

        $original = null;
        $added = false;
        $effective = false;
        // the try starts before the first session change, so a signal after any statement still restores the session
        try {
            $this->exec('SET SESSION lock_wait_timeout = ' . $this->lockWaitTimeout);
            if ($this->notice !== null) {
                ($this->notice)("Adding INVISIBLE index {$index->table}.{$index->name}; if this run is interrupted, remove it with: {$index->dropStatement()}");
            }

            $this->addIndex($index, $added);
            $this->exec("SET SESSION optimizer_switch = 'use_invisible_indexes=on'");
            ['result' => $after, 'context' => $context] = $this->analyzer->explainSql($sql, $params);
            $used = self::usesIndex($after['explain_result'], $index->name, self::namesOf($index->table, $context->aliases(), $this->database()));
            $effective = self::isEffective($before, $after, $used, $minImprovement);
        } catch (Throwable $e) {
            $original = $e;

            throw $e;
        } finally {
            $this->cleanUp($index, $added, $savedSwitch, $savedLockWait, $keep && $effective, $original);
        }

        return [
            'before' => $before,
            'after' => $after,
            'used' => $used,
            'effective' => $effective,
            'kept' => $keep && $effective,
        ];
    }

    /**
     * @param AnalysisResult $before
     * @param AnalysisResult $after
     */
    public static function isEffective(array $before, array $after, bool $used, float $minImprovement = self::DEFAULT_MIN_IMPROVEMENT): bool
    {
        if (! $used || $before['cost'] <= 0.0) {
            return false;
        }

        // unrounded, with a tolerance, so a reduction of exactly the minimum counts
        $reduction = ($before['cost'] - $after['cost']) / $before['cost'] * 100.0;

        return $reduction >= $minImprovement - 1e-9;
    }

    /**
     * The names a plan can give the table: itself and every alias of it, also one qualified with the
     * connection's own database ("app.posts p"). An alias of the same name in another schema does not count.
     *
     * @param array<string, string> $aliases alias => table key
     *
     * @return list<string>
     */
    private static function namesOf(string $table, array $aliases, string|null $database): array
    {
        $names = [$table];
        foreach ($aliases as $alias => $aliasedTable) {
            if ($aliasedTable !== $table && ($database === null || $aliasedTable !== $database . '.' . $table)) {
                continue;
            }

            $names[] = $alias;
        }

        return array_values(array_unique($names));
    }

    /** Change from $before to $after in percent, rounded to 2 places; 0.0 when $before is not positive. */
    public static function changePercent(float $before, float $after): float
    {
        if ($before <= 0.0) {
            return 0.0;
        }

        $percent = round(($after - $before) / $before * 100.0, 2);

        return $percent === -0.0 ? 0.0 : $percent;
    }

    /**
     * Whether a table access of the plan names the index as its key, alone or in an index merge.
     * With $tableNames, only an access to one of those names (the table and its aliases) counts, so a
     * same-named index on another table does not.
     *
     * @param array<array-key, mixed> $node
     * @param list<string>|null       $tableNames
     */
    public static function usesIndex(array $node, string $indexName, array|null $tableNames = null): bool
    {
        $key = $node['key'] ?? null;
        $tableMatches = $tableNames === null || in_array($node['table_name'] ?? null, $tableNames, true);
        // the name as a whole token: an index merge reports "union(a,b)", "sort_union(a,b)", "intersect(a,b)"
        if ($tableMatches && is_string($key) && preg_match('/(?<![\w$])' . preg_quote($indexName, '/') . '(?![\w$])/', $key)) {
            return true;
        }

        /** @var mixed $child */
        foreach ($node as $child) {
            if (is_array($child) && self::usesIndex($child, $indexName, $tableNames)) {
                return true;
            }
        }

        return false;
    }

    /** @throws IndexTrialUnsupported */
    private function assertSupported(): void
    {
        $stmt = $this->pdo->query('SELECT VERSION()');
        $version = $stmt === false ? false : $stmt->fetchColumn();
        // 8.0.18: EXPLAIN ANALYZE, which the measurement runs; invisible indexes came with 8.0.0
        // compare the numeric part only: version_compare() sorts "8.0.18-log" below "8.0.18"
        $number = is_string($version) && preg_match('/^\d+\.\d+\.\d+/', $version, $match) === 1 ? $match[0] : '0';
        if (! is_string($version) || stripos($version, 'mariadb') !== false || version_compare($number, '8.0.18', '<')) {
            throw new IndexTrialUnsupported(is_string($version) ? $version : 'unknown');
        }
    }

    private function indexExists(IndexDefinition $index): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
        if ($stmt === false || ! $stmt->execute([$index->table, $index->name])) {
            throw new RuntimeException('Failed to look up existing indexes');
        }

        return $stmt->fetchColumn() !== false;
    }

    /** @throws IndexAlreadyExists */
    private function assertAbsent(IndexDefinition $index): void
    {
        if ($this->indexExists($index)) {
            throw new IndexAlreadyExists("{$index->table}.{$index->name}");
        }
    }

    /**
     * use_invisible_indexes=on enables every invisible index, not only the trial one; with another one on
     * a table the statement reads, the measurement could not be attributed.
     *
     * @throws InvisibleIndexesPresent
     */
    private function assertNoInvisibleIndexes(string $sql, IndexDefinition $index): void
    {
        // a qualified table ("reporting.posts") is looked up in its own schema: the session enables its invisible indexes too
        $keys = array_values(array_unique(array_merge([$index->table], SchemaReader::tableNames($sql))));
        $conditions = [];
        $values = [];
        foreach ($keys as $key) {
            [$schema, $table] = TableReferences::split($key);
            $conditions[] = '(table_schema = COALESCE(?, DATABASE()) AND table_name = ?)';
            $values[] = $schema;
            $values[] = $table;
        }

        $stmt = $this->pdo->prepare("SELECT DISTINCT CONCAT(IF(table_schema = DATABASE(), '', CONCAT(table_schema, '.')), table_name, '.', index_name) FROM information_schema.statistics WHERE is_visible = 'NO' AND (" . implode(' OR ', $conditions) . ') ORDER BY 1');
        if ($stmt === false || ! $stmt->execute($values)) {
            throw new RuntimeException('Failed to look up invisible indexes');
        }

        /** @var list<string> $invisible */
        $invisible = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($invisible !== []) {
            throw new InvisibleIndexesPresent(implode(', ', $invisible));
        }
    }

    /** The connection's default database, null when none is selected */
    private function database(): string|null
    {
        $stmt = $this->pdo->query('SELECT DATABASE()');
        $database = $stmt === false ? false : $stmt->fetchColumn();

        return is_string($database) ? $database : null;
    }

    private function sessionVariable(string $name): string
    {
        $stmt = $this->pdo->query('SELECT @@session.' . $name);
        $value = $stmt === false ? false : $stmt->fetchColumn();
        if (! is_string($value) && ! is_numeric($value)) {
            throw new RuntimeException("Failed to read {$name}");
        }

        return (string) $value;
    }

    /**
     * Restores the session and removes (or, with $keep, publishes) the index. A failure to remove the
     * index is always reported, with the statement to run by hand; a failed session restore alone does
     * not replace the measurement's own exception.
     *
     * @throws IndexCleanupFailed
     */

    /**
     * Adds the trial index and reports that this run owns it. Signals are held from before the ALTER until
     * ownership is recorded, so an interrupt cannot land between the server adding the index and this run
     * knowing it did. A failed ALTER (1061 Duplicate key name: another connection made an index of that name
     * meanwhile) throws, and the index, not this run's, is left alone. $added is set inside the held region,
     * so a signal delivered when the mask lifts finds it already true.
     *
     * @param-out bool $added
     */
    private function addIndex(IndexDefinition $index, bool &$added): void
    {
        $masked = function_exists('pcntl_sigprocmask') && pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM]);
        try {
            $this->exec($index->addInvisibleStatement());
            $added = true;
        } finally {
            if ($masked) {
                pcntl_sigprocmask(SIG_UNBLOCK, [SIGINT, SIGTERM]);
            }
        }
    }

    private function cleanUp(IndexDefinition $index, bool $added, string $savedSwitch, string $savedLockWait, bool $keep, Throwable|null $original): void
    {
        // a SIGINT / SIGTERM turned into an exception must not cut the cleanup short; it is delivered afterwards
        $masked = function_exists('pcntl_sigprocmask') && pcntl_sigprocmask(SIG_BLOCK, [SIGINT, SIGTERM]);
        try {
            $this->restoreAndRemove($index, $added, $savedSwitch, $savedLockWait, $keep, $original);
        } finally {
            if ($masked) {
                pcntl_sigprocmask(SIG_UNBLOCK, [SIGINT, SIGTERM]);
            }
        }
    }

    /** @throws IndexCleanupFailed */
    private function restoreAndRemove(IndexDefinition $index, bool $added, string $savedSwitch, string $savedLockWait, bool $keep, Throwable|null $original): void
    {
        $sessionFailures = [];
        try {
            $this->exec("SET SESSION optimizer_switch = '{$savedSwitch}'");
        } catch (Throwable $e) {
            $sessionFailures[] = 'optimizer_switch restore: ' . $e->getMessage();
        }

        // only an index this run added is touched: a same-named index another connection made is not ours
        $indexFailure = null;
        $statement = $keep ? $index->makeVisibleStatement() : $index->dropStatement();
        try {
            if ($added) {
                $this->exec($statement);
            }
        } catch (Throwable $e) {
            $indexFailure = "run by hand: {$statement}; " . $e->getMessage();
        }

        try {
            $this->exec('SET SESSION lock_wait_timeout = ' . (int) $savedLockWait);
        } catch (Throwable $e) {
            $sessionFailures[] = 'lock_wait_timeout restore: ' . $e->getMessage();
        }

        if ($indexFailure === null && ($sessionFailures === [] || $original !== null)) {
            return;
        }

        $failures = $indexFailure === null ? $sessionFailures : [$indexFailure, ...$sessionFailures];

        throw new IndexCleanupFailed(implode('; ', $failures), 0, $original);
    }

    private function exec(string $statement): void
    {
        if ($this->pdo->exec($statement) === false) {
            throw new RuntimeException("Statement failed: {$statement}");
        }
    }
}

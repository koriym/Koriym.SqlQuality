<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Closure;
use Koriym\SqlQuality\Exception\IndexAlreadyExists;
use Koriym\SqlQuality\Exception\IndexCleanupFailed;
use Koriym\SqlQuality\Exception\IndexTrialUnsupported;
use Koriym\SqlQuality\Exception\InvisibleIndexesPresent;
use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;
use Throwable;

use function array_fill;
use function array_merge;
use function array_unique;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_numeric;
use function is_string;
use function preg_match;
use function preg_quote;
use function round;
use function stripos;
use function version_compare;

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

    /** @param (Closure(string): void)|null $notice */
    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlFileAnalyzer $analyzer,
        private readonly Closure|null $notice = null,
        private readonly int $lockWaitTimeout = self::DEFAULT_LOCK_WAIT_TIMEOUT,
    ) {
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
        $this->assertSupported();
        $this->assertAbsent($index);
        $this->assertNoInvisibleIndexes($sql, $index);
        // bind first: an unbound parameter fails here, before any DDL
        $this->analyzer->bind($sql, $params);

        $before = $this->analyzer->analyzeSql($sql, $params);
        $savedSwitch = $this->sessionVariable('optimizer_switch');
        $savedLockWait = $this->sessionVariable('lock_wait_timeout');
        $this->exec('SET SESSION lock_wait_timeout = ' . $this->lockWaitTimeout);

        $original = null;
        $added = false;
        $effective = false;
        try {
            if ($this->notice !== null) {
                ($this->notice)("Adding INVISIBLE index {$index->table}.{$index->name}; if this run is interrupted, remove it with: {$index->dropStatement()}");
            }

            $this->exec($index->addInvisibleStatement());
            $added = true;
            $this->exec("SET SESSION optimizer_switch = 'use_invisible_indexes=on'");
            $after = $this->analyzer->analyzeSql($sql, $params);
            $used = self::usesIndex($after['explain_result'], $index->name);
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
        return $used && self::changePercent($before['cost'], $after['cost']) <= -$minImprovement;
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
     *
     * @param array<array-key, mixed> $node
     */
    public static function usesIndex(array $node, string $indexName): bool
    {
        $key = $node['key'] ?? null;
        if (is_string($key) && preg_match('/(?:^|,)' . preg_quote($indexName, '/') . '(?:,|$)/', $key)) {
            return true;
        }

        /** @var mixed $child */
        foreach ($node as $child) {
            if (is_array($child) && self::usesIndex($child, $indexName)) {
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
        if (! is_string($version) || stripos($version, 'mariadb') !== false || version_compare($version, '8.0.18', '<')) {
            throw new IndexTrialUnsupported(is_string($version) ? $version : 'unknown');
        }
    }

    /** @throws IndexAlreadyExists */
    private function assertAbsent(IndexDefinition $index): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1');
        if ($stmt === false || ! $stmt->execute([$index->table, $index->name])) {
            throw new RuntimeException('Failed to look up existing indexes');
        }

        if ($stmt->fetchColumn() !== false) {
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
        $tables = array_values(array_unique(array_merge([$index->table], SchemaReader::tableNames($sql))));
        $placeholders = implode(', ', array_fill(0, count($tables), '?'));
        $stmt = $this->pdo->prepare("SELECT DISTINCT CONCAT(table_name, '.', index_name) FROM information_schema.statistics WHERE table_schema = DATABASE() AND is_visible = 'NO' AND table_name IN ({$placeholders}) ORDER BY 1");
        if ($stmt === false || ! $stmt->execute($tables)) {
            throw new RuntimeException('Failed to look up invisible indexes');
        }

        /** @var list<string> $invisible */
        $invisible = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if ($invisible !== []) {
            throw new InvisibleIndexesPresent(implode(', ', $invisible));
        }
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
    private function cleanUp(IndexDefinition $index, bool $added, string $savedSwitch, string $savedLockWait, bool $keep, Throwable|null $original): void
    {
        $sessionFailures = [];
        try {
            $this->exec("SET SESSION optimizer_switch = '{$savedSwitch}'");
        } catch (Throwable $e) {
            $sessionFailures[] = 'optimizer_switch restore: ' . $e->getMessage();
        }

        $indexFailure = null;
        if ($added) {
            $statement = $keep ? $index->makeVisibleStatement() : $index->dropStatement();
            try {
                $this->exec($statement);
            } catch (Throwable $e) {
                $indexFailure = "run by hand: {$statement}; " . $e->getMessage();
            }
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

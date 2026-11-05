<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\IndexAlreadyExists;
use Koriym\SqlQuality\Exception\IndexCleanupFailed;
use Koriym\SqlQuality\Exception\IndexTrialUnsupported;
use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;
use Throwable;

use function implode;
use function is_array;
use function is_string;
use function preg_match;
use function preg_quote;
use function round;
use function str_contains;
use function stripos;
use function version_compare;

/**
 * Measures a statement with and without a candidate index, without changing the plans of other sessions.
 *
 * The index is added INVISIBLE (MySQL 8.0+): the optimizer ignores it except in this session, where
 * optimizer_switch use_invisible_indexes=on is set for the measurement. Afterwards the index is dropped,
 * or, with $keep, made visible. Adding the index is still real DDL: it reads the whole table and needs
 * the ALTER privilege; ALGORITHM=INPLACE, LOCK=NONE makes it fail rather than block writes.
 * With $keep the index stays, visible, only when the trial is effective.
 *
 * @psalm-import-type AnalysisResult from Types
 * @psalm-import-type ExplainResult from Types
 */
final class IndexTrial
{
    public const DEFAULT_MIN_IMPROVEMENT = 5.0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlFileAnalyzer $analyzer,
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
        // bind first: an unbound parameter fails here, before any DDL
        $this->analyzer->bind($sql, $params);

        $before = $this->analyzer->analyzeSql($sql, $params);
        $this->exec($index->addInvisibleStatement());

        $savedSwitch = $this->optimizerSwitch();
        $original = null;
        $effective = false;
        try {
            $this->exec("SET SESSION optimizer_switch = 'use_invisible_indexes=on'");
            $after = $this->analyzer->analyzeSql($sql, $params);
            $used = self::usesIndex($after['explain_result'], $index->name);
            $effective = self::isEffective($before, $after, $used, $minImprovement);
        } catch (Throwable $e) {
            $original = $e;

            throw $e;
        } finally {
            $this->cleanUp($index, $savedSwitch, $keep && $effective, $original);
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
        if (! is_string($version) || stripos($version, 'mariadb') !== false || version_compare($version, '8.0.0', '<')) {
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

    private function optimizerSwitch(): string
    {
        $stmt = $this->pdo->query('SELECT @@session.optimizer_switch');
        $switch = $stmt === false ? false : $stmt->fetchColumn();
        if (! is_string($switch)) {
            throw new RuntimeException('Failed to read optimizer_switch');
        }

        return $switch;
    }

    /** @throws IndexCleanupFailed */
    private function cleanUp(IndexDefinition $index, string $savedSwitch, bool $keep, Throwable|null $original): void
    {
        $failures = [];
        try {
            $this->exec("SET SESSION optimizer_switch = '{$savedSwitch}'");
        } catch (Throwable $e) {
            $failures[] = 'optimizer_switch restore: ' . $e->getMessage();
        }

        $statement = $keep ? $index->makeVisibleStatement() : $index->dropStatement();
        try {
            $this->exec($statement);
        } catch (Throwable $e) {
            $failures[] = "run by hand: {$statement}; " . $e->getMessage();
        }

        if ($failures === []) {
            return;
        }

        $message = implode('; ', $failures);
        if ($original !== null && ! str_contains($message, 'run by hand')) {
            // the measurement's exception propagates; a failed switch restore alone does not replace it
            return;
        }

        throw new IndexCleanupFailed($message, 0, $original);
    }

    private function exec(string $statement): void
    {
        if ($this->pdo->exec($statement) === false) {
            throw new RuntimeException("Statement failed: {$statement}");
        }
    }
}

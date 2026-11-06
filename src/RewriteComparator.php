<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\ActiveTransactionRejected;
use Koriym\SqlQuality\Exception\NotReadOnlySelect;
use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;

/**
 * Checks that a rewritten SELECT returns the same rows as the original, and measures both.
 *
 * Both result sets are read in one read-only transaction with a consistent snapshot. Equivalence is judged on the result sets the current
 * data produces: the same column names in the same order, and the same multiset of rows. Whether the
 * order also matches is reported separately; it matters only when the statement has an ORDER BY.
 *
 * @psalm-import-type AnalysisResult from Types
 */
final class RewriteComparator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlFileAnalyzer $analyzer,
    ) {
    }

    /**
     * @param array<string, mixed> $beforeParams
     * @param array<string, mixed> $afterParams
     *
     * @return array{before: array{analysis: AnalysisResult, rows: ResultFingerprint}, after: array{analysis: AnalysisResult, rows: ResultFingerprint}, equivalent: bool, same_order: bool, same_columns: bool}
     *
     * @throws RuntimeException
     */
    public function compare(string $beforeSql, array $beforeParams, string $afterSql, array $afterParams): array
    {
        $beforeBound = $this->bound($beforeSql, $beforeParams);
        $afterBound = $this->bound($afterSql, $afterParams);
        [$beforeRows, $afterRows] = $this->fingerprints($beforeBound, $afterBound);
        $before = ['analysis' => $this->analyzer->analyzeSql($beforeSql, $beforeParams), 'rows' => $beforeRows];
        $after = ['analysis' => $this->analyzer->analyzeSql($afterSql, $afterParams), 'rows' => $afterRows];
        $sameColumns = $before['rows']->columns === $after['rows']->columns;

        return [
            'before' => $before,
            'after' => $after,
            'equivalent' => $sameColumns && $before['rows']->sameRowsAs($after['rows']),
            'same_order' => $sameColumns && $before['rows']->sameOrderAs($after['rows']),
            'same_columns' => $sameColumns,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @throws RuntimeException
     */
    private function bound(string $sql, array $params): string
    {
        if (! (new SqlSafetyClassifier($this->analyzer->quoteRules()))->isReadOnlySelect($sql)) {
            throw new NotReadOnlySelect($sql);
        }

        return $this->analyzer->bind($sql, $params);
    }

    /**
     * Both result sets from one read-only transaction with a consistent snapshot, so a write committed
     * between the two reads cannot make equivalent statements look different, or the reverse.
     *
     * @return array{0: ResultFingerprint, 1: ResultFingerprint}
     *
     * @throws RuntimeException
     */
    private function fingerprints(string $before, string $after): array
    {
        if ($this->pdo->inTransaction()) {
            throw new ActiveTransactionRejected();
        }

        // WITH CONSISTENT SNAPSHOT only holds under REPEATABLE READ; this applies to the next transaction only
        $this->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
        try {
            return [ResultFingerprint::of($this->pdo, $before), ResultFingerprint::of($this->pdo, $after)];
        } finally {
            $this->exec('ROLLBACK');
        }
    }

    private function exec(string $statement): void
    {
        if ($this->pdo->exec($statement) === false) {
            throw new RuntimeException("Statement failed: {$statement}");
        }
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\NotReadOnlySelect;
use Koriym\SqlQuality\Exception\RuntimeException;
use PDO;

/**
 * Checks that a rewritten SELECT returns the same rows as the original, and measures both.
 *
 * Both statements run in a read-only session. Equivalence is judged on the result sets the current
 * data produces: the same column names in the same order, and the same multiset of rows. Whether the
 * order also matches is reported separately; it matters only when the statement has an ORDER BY.
 *
 * @psalm-import-type AnalysisResult from Types
 */
final class RewriteComparator
{
    private readonly ReadOnlySession $readOnlySession;

    public function __construct(
        private readonly PDO $pdo,
        private readonly SqlFileAnalyzer $analyzer,
    ) {
        $this->readOnlySession = new ReadOnlySession($pdo);
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
        $before = $this->measure($beforeSql, $beforeParams);
        $after = $this->measure($afterSql, $afterParams);
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
     * @return array{analysis: AnalysisResult, rows: ResultFingerprint}
     *
     * @throws RuntimeException
     */
    private function measure(string $sql, array $params): array
    {
        if (! (new SqlSafetyClassifier($this->analyzer->quoteRules()))->isReadOnlySelect($sql)) {
            throw new NotReadOnlySelect($sql);
        }

        $interpolatedSql = $this->analyzer->bind($sql, $params);
        $rows = $this->readOnlySession->run(fn (): ResultFingerprint => ResultFingerprint::of($this->pdo, $interpolatedSql));

        return ['analysis' => $this->analyzer->analyzeSql($sql, $params), 'rows' => $rows];
    }
}

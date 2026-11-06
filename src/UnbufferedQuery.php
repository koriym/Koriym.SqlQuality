<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\QueryFailed;
use PDO;
use PDOStatement;

use function is_array;

/**
 * Runs a query with the MySQL driver's result buffering off, so rows reach PHP one at a time and a
 * large result set is never held in memory whole. The connection's buffering setting is restored.
 */
final class UnbufferedQuery
{
    /**
     * @param callable(list<mixed>): void         $row       receives each row, as a list
     * @param (callable(PDOStatement): void)|null $statement receives the statement before the first row
     *
     * @throws QueryFailed
     */
    public static function each(PDO $pdo, string $sql, callable $row, callable|null $statement = null): void
    {
        $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        $buffered = $isMysql ? (bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY) : false;
        if ($buffered) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $stmt = $pdo->query($sql);
            if ($stmt === false) {
                throw new QueryFailed($sql);
            }

            try {
                if ($statement !== null) {
                    $statement($stmt);
                }

                while (is_array($values = $stmt->fetch(PDO::FETCH_NUM))) {
                    /** @var list<mixed> $values */
                    $row($values);
                }
            } finally {
                // an unbuffered result must be read to the end or closed before the next query
                $stmt->closeCursor();
            }
        } finally {
            if ($buffered) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }
}

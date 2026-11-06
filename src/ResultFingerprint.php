<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\QueryFailed;
use PDO;
use PDOStatement;

use function array_map;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function hexdec;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function sprintf;
use function strlen;
use function substr;

/**
 * Column names, row count and two checksums of a result set, computed while the rows stream past:
 * one over the rows in order, one over the multiset of rows regardless of order. Each value is encoded with
 * the PHP type the driver returned it as (int, float, string, null), so a rewrite that turns an integer
 * column into a string one is not equivalent: the application would receive another type. A float keeps
 * all 17 significant digits.
 */
final class ResultFingerprint
{
    /** @param list<string> $columns */
    private function __construct(
        public readonly array $columns,
        public readonly int $rowCount,
        public readonly string $orderedChecksum,
        public readonly string $unorderedChecksum,
    ) {
    }

    /** @throws QueryFailed */
    public static function of(PDO $pdo, string $interpolatedSql): self
    {
        $columns = [];
        $ordered = hash_init('sha256');
        // eight 16-bit lanes of each row's hash, summed without any modulus: a multiset hash that does not
        // depend on row order and keeps every multiplicity (a modulus would let N copies of a row cancel out).
        // A sum past PHP_INT_MAX turns into a float, which stays exact up to 2^53, so ~1.3e11 rows.
        /** @var list<int|float> $lanes */
        $lanes = [0, 0, 0, 0, 0, 0, 0, 0];
        $rowCount = 0;
        UnbufferedQuery::each(
            $pdo,
            $interpolatedSql,
            static function (array $row) use ($ordered, &$lanes, &$rowCount): void {
                $encoded = self::encode($row);
                hash_update($ordered, $encoded);
                $rowHash = hash('sha256', $encoded);
                for ($lane = 0; $lane < 8; $lane++) {
                    $lanes[$lane] += (int) hexdec(substr($rowHash, $lane * 4, 4));
                }

                $rowCount++;
            },
            static function (PDOStatement $stmt) use (&$columns): void {
                $columns = self::columns($stmt);
            },
        );

        return new self(
            $columns,
            $rowCount,
            hash_final($ordered),
            implode('-', array_map(static fn (int|float $lane): string => sprintf('%.0f', $lane), $lanes)),
        );
    }

    /**
     * Length-prefixed values, so neither a separator inside a value nor binary data can make two rows encode alike.
     *
     * @param array<array-key, mixed> $row
     */
    private static function encode(array $row): string
    {
        return implode('', array_map(static function (mixed $value): string {
            if ($value === null) {
                return 'N;';
            }

            // the type tag keeps 5 and '5' apart; a float is written with 17 significant digits, since
            // (string) keeps only 14 (the precision ini) and 0.1e0 + 0.2e0 and 0.3e0 would encode alike
            [$tag, $string] = match (true) {
                is_string($value) => ['S', $value],
                is_int($value) => ['I', (string) $value],
                is_float($value) => ['F', sprintf('%.17g', $value)],
                is_bool($value) => ['B', $value ? '1' : '0'],
                default => ['X', (string) $value],
            };

            return $tag . strlen($string) . ':' . $string;
        }, $row)) . "\n";
    }

    /** @return list<string> */
    private static function columns(PDOStatement $stmt): array
    {
        $columns = [];
        $count = $stmt->columnCount();
        for ($i = 0; $i < $count; $i++) {
            $meta = $stmt->getColumnMeta($i);
            $columns[] = is_array($meta) ? (string) $meta['name'] : (string) $i;
        }

        return $columns;
    }

    public function sameRowsAs(self $other): bool
    {
        return $this->rowCount === $other->rowCount && $this->unorderedChecksum === $other->unorderedChecksum;
    }

    public function sameOrderAs(self $other): bool
    {
        return $this->rowCount === $other->rowCount && $this->orderedChecksum === $other->orderedChecksum;
    }
}

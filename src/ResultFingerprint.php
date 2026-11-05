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
use function is_float;
use function is_string;
use function sprintf;
use function strlen;
use function substr;

/**
 * Column names, row count and two checksums of a result set, computed while the rows stream past:
 * one over the rows in order, one over the multiset of rows regardless of order. Every non-NULL value
 * is compared as its string form, so the same value fetched as int or as string matches; a float keeps
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
        // eight 16-bit lanes summed per row: a multiset hash that does not depend on row order, and whose
        // sums stay within a 32-bit int
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
                    $lanes[$lane] = ($lanes[$lane] + (int) hexdec(substr($rowHash, $lane * 4, 4))) % 0x10000;
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
            implode('', array_map(static fn (int $lane): string => sprintf('%04x', $lane), $lanes)),
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

            // a float is written with 17 significant digits: (string) keeps only 14 (the precision ini),
            // so 0.1e0 + 0.2e0 and 0.3e0 would encode alike
            $string = match (true) {
                is_string($value) => $value,
                is_float($value) => sprintf('%.17g', $value),
                default => (string) $value,
            };

            return 'S' . strlen($string) . ':' . $string;
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

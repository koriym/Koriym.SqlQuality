<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;
use RuntimeException;

use function count;
use function str_contains;

final class ReadOnlySessionTest extends MySqlTestCase
{
    private const PROBE_ID = 999999;
    private const PROBE_INSERT = 'INSERT INTO users (id, name, email) VALUES (999999, \'probe\', \'probe@example.com\')';

    /** @return array<string, array{string}> */
    public function flagProvider(): array
    {
        return ['read write' => ['0'], 'read only' => ['1']];
    }

    public function testRunRejectsInsert(): void
    {
        $pdo = $this->connect();

        try {
            (new ReadOnlySession($pdo))->run(static fn (): int|false => $pdo->exec(self::PROBE_INSERT));
            $this->fail('INSERT was not rejected');
        } catch (PDOException $e) {
            $this->assertSame('25006', (string) $e->getCode());
        }

        $this->assertSame('0', $this->scalar($pdo, 'SELECT COUNT(*) FROM users WHERE id = ' . self::PROBE_ID));
    }

    public function testRunAllowsSelect(): void
    {
        $pdo = $this->connect();

        $rows = (new ReadOnlySession($pdo))->run(static fn (): array => (array) $pdo->query('SELECT id FROM users LIMIT 3')->fetchAll(PDO::FETCH_NUM));

        $this->assertSame(3, count($rows));
    }

    /** @dataProvider flagProvider */
    public function testRestoresFlagAfterReturn(string $flag): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION transaction_read_only = ' . $flag);

        $returned = (new ReadOnlySession($pdo))->run(static fn (): string => 'returned');

        $this->assertSame('returned', $returned);
        $this->assertSame($flag, $this->scalar($pdo, 'SELECT @@session.transaction_read_only'));
    }

    /** @dataProvider flagProvider */
    public function testRestoresFlagAfterException(string $flag): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION transaction_read_only = ' . $flag);

        try {
            (new ReadOnlySession($pdo))->run(static fn (): int|false => $pdo->exec(self::PROBE_INSERT));
            $this->fail('INSERT was not rejected');
        } catch (PDOException) {
            $this->assertSame($flag, $this->scalar($pdo, 'SELECT @@session.transaction_read_only'));
        }
    }

    public function testOriginalExceptionPropagatesWhenRestoreFails(): void
    {
        $execCalls = 0;
        $pdo = new FakePdo(
            query: static fn (): FakeStatement => new FakeStatement('0'),
            exec: static function () use (&$execCalls): int {
                $execCalls++;
                if ($execCalls === 2) {
                    throw new PDOException('restore failed');
                }

                return 0;
            },
        );

        try {
            (new ReadOnlySession($pdo))->run(static function (): never {
                throw new RuntimeException('original failure');
            });
            $this->fail('Expected exception was not thrown');
        } catch (RuntimeException $e) {
            $this->assertSame('original failure', $e->getMessage());
        }
    }

    public function testRestoreFailurePropagatesWhenThereIsNoOriginalException(): void
    {
        $execCalls = 0;
        $pdo = new FakePdo(
            query: static fn (): FakeStatement => new FakeStatement('0'),
            exec: static function () use (&$execCalls): int {
                $execCalls++;
                if ($execCalls === 2) {
                    throw new PDOException('restore failed');
                }

                return 0;
            },
        );

        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('restore failed');

        (new ReadOnlySession($pdo))->run(static fn (): string => 'returned');
    }

    public function testFallsBackToTxReadOnlyWhenTransactionReadOnlyIsUnavailable(): void
    {
        $execCalls = [];
        $pdo = new FakePdo(
            query: static function (string $sql): FakeStatement {
                if (str_contains($sql, 'tx_read_only')) {
                    return new FakeStatement('0');
                }

                throw new PDOException("Unknown system variable 'transaction_read_only'");
            },
            exec: static function (string $sql) use (&$execCalls): int {
                $execCalls[] = $sql;

                return 0;
            },
        );

        $returned = (new ReadOnlySession($pdo))->run(static fn (): string => 'returned');

        $this->assertSame('returned', $returned);
        $this->assertSame(['SET SESSION TRANSACTION READ ONLY', 'SET SESSION tx_read_only = 0'], $execCalls);
    }

    private function scalar(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);

        return $statement === false ? '' : (string) $statement->fetchColumn();
    }
}

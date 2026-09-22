<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use PDOException;

use function count;

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

    private function scalar(PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);

        return $statement === false ? '' : (string) $statement->fetchColumn();
    }
}

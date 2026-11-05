<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

final class ResultFingerprintTest extends MySqlTestCase
{
    public function testRowOrderChangesOnlyTheOrderedChecksum(): void
    {
        $pdo = $this->connect();
        $ascending = ResultFingerprint::of($pdo, 'SELECT id, name FROM users WHERE id <= 5 ORDER BY id');
        $descending = ResultFingerprint::of($pdo, 'SELECT id, name FROM users WHERE id <= 5 ORDER BY id DESC');

        $this->assertSame(['id', 'name'], $ascending->columns);
        $this->assertSame(5, $ascending->rowCount);
        $this->assertTrue($ascending->sameRowsAs($descending));
        $this->assertFalse($ascending->sameOrderAs($descending));
    }

    public function testADuplicatedRowIsNotTheSameMultiset(): void
    {
        $pdo = $this->connect();
        $distinct = ResultFingerprint::of($pdo, 'SELECT 1 AS a UNION ALL SELECT 2');
        $duplicated = ResultFingerprint::of($pdo, 'SELECT 1 AS a UNION ALL SELECT 1');

        $this->assertFalse($distinct->sameRowsAs($duplicated));
    }

    public function testNullAndEmptyStringDiffer(): void
    {
        $pdo = $this->connect();

        $this->assertFalse(ResultFingerprint::of($pdo, 'SELECT NULL AS a')->sameRowsAs(ResultFingerprint::of($pdo, "SELECT '' AS a")));
    }

    public function testAValueFetchedAsANumberMatchesTheSameValueAsAString(): void
    {
        $pdo = $this->connect();

        $this->assertTrue(ResultFingerprint::of($pdo, 'SELECT 5 AS a')->sameRowsAs(ResultFingerprint::of($pdo, "SELECT '5' AS a")));
    }
}

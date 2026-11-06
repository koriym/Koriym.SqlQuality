<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;

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

    public function testAnIntegerAndTheSameDigitsAsAStringDiffer(): void
    {
        $pdo = $this->connect();

        // the application receives int 5 from one and string '5' from the other
        $this->assertFalse(ResultFingerprint::of($pdo, 'SELECT 5 AS a')->sameRowsAs(ResultFingerprint::of($pdo, "SELECT '5' AS a")));
        $this->assertTrue(ResultFingerprint::of($pdo, 'SELECT 5 AS a')->sameRowsAs(ResultFingerprint::of($pdo, 'SELECT 2 + 3 AS a')));
    }

    public function testDoublesThatDifferBeyondFourteenDigitsDiffer(): void
    {
        $pdo = $this->connect();

        $this->assertFalse(ResultFingerprint::of($pdo, 'SELECT 0.1e0 + 0.2e0 AS v')->sameRowsAs(ResultFingerprint::of($pdo, 'SELECT 0.3e0 AS v')));
        $this->assertTrue(ResultFingerprint::of($pdo, 'SELECT 0.5e0 AS v')->sameRowsAs(ResultFingerprint::of($pdo, 'SELECT 0.25e0 * 2 AS v')));
    }

    public function testTheSameValuesWithOtherMultiplicitiesDiffer(): void
    {
        $pdo = $this->connect();
        $more1 = ResultFingerprint::of($pdo, 'SELECT 1 AS a UNION ALL SELECT 1 UNION ALL SELECT 2');
        $more2 = ResultFingerprint::of($pdo, 'SELECT 1 AS a UNION ALL SELECT 2 UNION ALL SELECT 2');

        $this->assertSame($more1->rowCount, $more2->rowCount);
        $this->assertFalse($more1->sameRowsAs($more2));
    }

    public function testTheConnectionKeepsBufferingAfterwards(): void
    {
        $pdo = $this->connect();
        ResultFingerprint::of($pdo, 'SELECT id FROM users');

        $this->assertTrue((bool) $pdo->getAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY));
    }

    public function testMultiplicitiesThatDifferBy65536AreStillTold(): void
    {
        $pdo = $this->connect();
        $pdo->exec('SET SESSION cte_max_recursion_depth = 200000');
        $cte = 'WITH RECURSIVE cte(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM cte WHERE n < 131072) ';

        $ones = ResultFingerprint::of($pdo, $cte . 'SELECT 1 AS a FROM cte');
        $half = ResultFingerprint::of($pdo, $cte . 'SELECT IF(n <= 65536, 1, 2) AS a FROM cte');

        $this->assertSame($ones->rowCount, $half->rowCount);
        $this->assertFalse($ones->sameRowsAs($half));
    }
}

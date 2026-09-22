<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function array_column;
use function array_keys;
use function array_unique;
use function array_values;
use function sort;

use const SORT_STRING;

final class DetectorCorpusTest extends TestCase
{
    /** @return array<string, list<string>> */
    private static function expected(): array
    {
        /** @var array<string, list<string>> */
        return require __DIR__ . '/fixtures/expected.php';
    }

    /** @return iterable<string, array{string}> */
    public static function fixtureProvider(): iterable
    {
        foreach (Fixture::names() as $name) {
            yield $name => [$name];
        }
    }

    public function testEveryFixtureHasAnExpectation(): void
    {
        $names = array_keys(self::expected());
        sort($names, SORT_STRING);

        $this->assertSame(Fixture::names(), $names);
    }

    /** @dataProvider fixtureProvider */
    public function testDetectedTypesMatchExpectation(string $name): void
    {
        $fixture = Fixture::load($name);

        $issues = (new ExplainAnalyzer())->analyze($fixture['explain'], $fixture['warnings']);
        $types = array_values(array_unique(array_column($issues, 'type')));
        sort($types, SORT_STRING);

        $this->assertSame(self::expected()[$name], $types);
    }
}

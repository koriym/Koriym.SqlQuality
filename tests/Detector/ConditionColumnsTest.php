<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use PHPUnit\Framework\TestCase;

use function array_column;

final class ConditionColumnsTest extends TestCase
{
    public function testClassifiesRangeAndLeadingWildcardColumns(): void
    {
        $groups = ConditionColumns::forAlias(
            "(`test`.`comments`.`post_id` between 1 and 1000) and (`test`.`comments`.`content` like '%rare%')",
            'comments',
        );

        $this->assertSame(['post_id'], array_column($groups['range'], 'column'));
        $this->assertSame(['content'], array_column($groups['leadingWildcard'], 'column'));
        $this->assertSame([], $groups['equality']);
    }

    public function testClassifiesEqualityAndInAsEquality(): void
    {
        $groups = ConditionColumns::forAlias('(`test`.`orders`.`reference_code` = 12345)', 'orders');

        $this->assertSame(['reference_code'], array_column($groups['equality'], 'column'));
    }

    public function testExcludesColumnsWrappedInAFunctionFromEquality(): void
    {
        $groups = ConditionColumns::forAlias("(cast(`test`.`posts`.`created_at` as date) = '2024-01-01')", 'posts');

        $this->assertSame([], $groups['equality']);
        $this->assertSame('created_at', $groups['functionWrapped'][0]['column']);
        $this->assertSame('cast', $groups['functionWrapped'][0]['function']);
    }

    public function testAConditionJoinedByOrYieldsNoColumnsInAnyGroup(): void
    {
        $groups = ConditionColumns::forAlias("((`test`.`posts`.`user_id` = 1) or (`test`.`posts`.`status` = 'published'))", 'posts');

        $this->assertSame(['equality' => [], 'range' => [], 'leadingWildcard' => [], 'functionWrapped' => []], $groups);
    }

    public function testOnlyReturnsColumnsForTheRequestedAlias(): void
    {
        $groups = ConditionColumns::forAlias("(`test`.`p`.`status` = 'published')", 'c');

        $this->assertSame([], $groups['equality']);
    }
}

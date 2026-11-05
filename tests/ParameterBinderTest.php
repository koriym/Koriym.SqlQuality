<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\EmptyListParameter;
use Koriym\SqlQuality\Exception\InvalidParameterValue;
use Koriym\SqlQuality\Exception\UnboundParameter;
use PDO;
use PHPUnit\Framework\TestCase;

final class ParameterBinderTest extends TestCase
{
    private ParameterBinder $binder;

    protected function setUp(): void
    {
        $this->binder = new ParameterBinder(new PDO('sqlite::memory:'));
    }

    public function testPlaceholdersIgnoreLiteralsCommentsAndAssignments(): void
    {
        $sql = "SELECT ':quoted', `:name` FROM t -- :commented\nWHERE a = :a AND b IN (:list) AND @v := 1 AND t = '10:30' AND c = :a";

        $this->assertSame(['a', 'list'], ParameterBinder::placeholders($sql));
    }

    public function testBindQuotesStringsAndKeepsNumbersNullsAndBooleans(): void
    {
        $sql = $this->binder->bind('SELECT :s, :i, :f, :n, :b, :list', [
            's' => "O'Brien",
            'i' => 42,
            'f' => 1.5,
            'n' => null,
            'b' => true,
            'list' => ['x', 2],
        ]);

        $this->assertSame("SELECT 'O''Brien', 42, 1.5, NULL, 1, 'x',2", $sql);
    }

    public function testANameThatPrefixesAnotherIsNotReplacedInsideIt(): void
    {
        $this->assertSame('SELECT 1, 2', $this->binder->bind('SELECT :id, :id2', ['id' => 1, 'id2' => 2]));
    }

    public function testAValueWithADollarSignIsInsertedVerbatim(): void
    {
        $this->assertSame('SELECT \'$1 and \\1\'', $this->binder->bind('SELECT :v', ['v' => '$1 and \\1']));
    }

    public function testAMissingParameterIsNamed(): void
    {
        $this->expectException(UnboundParameter::class);
        $this->expectExceptionMessage('from, to');

        $this->binder->bind('SELECT * FROM t WHERE d BETWEEN :from AND :to AND id = :id', ['id' => 1]);
    }

    public function testAnEmptyListIsRejected(): void
    {
        $this->expectException(EmptyListParameter::class);
        $this->expectExceptionMessage('ids');

        $this->binder->bind('SELECT * FROM t WHERE id IN (:ids)', ['ids' => []]);
    }

    public function testAPlaceholderInsideALiteralOrACommentIsLeftAlone(): void
    {
        $sql = $this->binder->bind("SELECT * FROM posts WHERE title = 'Re: :id' -- see :id\nAND id = :id /* :id */ AND `:id` = 1", ['id' => 5]);

        $this->assertSame("SELECT * FROM posts WHERE title = 'Re: :id' -- see :id\nAND id = 5 /* :id */ AND `:id` = 1", $sql);
    }

    public function testAnInsertedValueIsNotScannedAgainWhateverTheParameterOrder(): void
    {
        $sql = 'SELECT :a, :b';

        $this->assertSame("SELECT 'x :b y', 7", $this->binder->bind($sql, ['a' => 'x :b y', 'b' => 7]));
        $this->assertSame("SELECT 'x :b y', 7", $this->binder->bind($sql, ['b' => 7, 'a' => 'x :b y']));
    }

    public function testABackslashEscapedQuoteDoesNotEndTheLiteralUnderTheDefaultRules(): void
    {
        $this->assertSame("SELECT 'it\\'s :id', 1", $this->binder->bind("SELECT 'it\\'s :id', :id", ['id' => 1]));
        // under NO_BACKSLASH_ESCAPES the literal ends at \', so the first :id is code and the rest is an open literal
        $this->assertSame("SELECT 'it\\'s 1', :id", $this->binder->bind("SELECT 'it\\'s :id', :id", ['id' => 1], new QuoteRules(backslashEscapes: false)));
    }

    public function testAVersionedCommentIsScannedAsCode(): void
    {
        $this->assertSame('SELECT /*!80000 1, */ 2', $this->binder->bind('SELECT /*!80000 :a, */ 2', ['a' => 1]));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidValueProvider(): iterable
    {
        yield 'nested list' => [[1, [2, 3]]];
        yield 'object' => [['k' => 'v']];
        yield 'object in list' => [[1, ['k' => 'v']]];
    }

    /** @dataProvider invalidValueProvider */
    public function testAValueThatIsNotAScalarOrAListOfScalarsIsRejected(mixed $value): void
    {
        $this->expectException(InvalidParameterValue::class);
        $this->expectExceptionMessage('ids:');

        $this->binder->bind('SELECT * FROM t WHERE id IN (:ids)', ['ids' => $value]);
    }
}

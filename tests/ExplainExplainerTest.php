<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function str_replace;
use function trim;

class ExplainExplainerTest extends TestCase
{
    private ExplainExplainer $explainer;

    protected function setUp(): void
    {
        $this->explainer = new ExplainExplainer();
    }

    /** @test */
    public function simpleJoinExplanation(): void
    {
        $tree = new TreeNode(
            'JOIN',
            [],
            [
                new TreeNode(
                    'Index lookup',
                    [
                        'key' => 'idx_fk_film_id',
                        'rows' => '2',
                    ],
                    [
                        new TreeNode(
                            'Table',
                            ['table' => 'film_actor'],
                        ),
                    ],
                ),
                new TreeNode(
                    'Table scan',
                    ['rows' => '952'],
                    [
                        new TreeNode(
                            'Table',
                            [
                                'table' => 'film',
                                'possible_keys' => 'PRIMARY',
                            ],
                        ),
                    ],
                ),
            ],
        );

        $expected = <<<'EXPECTED'
This operation performs a nested loop join between the following tables:

  Using an index for efficient table lookup:
  - Using index 'idx_fk_film_id'
  - Examining approximately 2 rows

    Accessing table 'film_actor':

  Performing a full table scan (reading all rows):
  - Examining approximately 952 rows

    Accessing table 'film':
    - Could potentially use indexes: PRIMARY
EXPECTED;

        $this->assertSame($this->normalizeLineEndings($expected), $this->normalizeLineEndings($this->explainer->explain($tree)));
    }

    private function normalizeLineEndings(string $string): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($string));
    }
}

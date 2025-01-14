<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PHPUnit\Framework\TestCase;

use function str_replace;
use function trim;

class ExplainTreeVisualizerTest extends TestCase
{
    private ExplainParser $parser;
    private ExplainTreeVisualizer $visualizer;

    protected function setUp(): void
    {
        $this->parser = new ExplainParser();
        $this->visualizer = new ExplainTreeVisualizer();
    }

    /** @test */
    public function simpleTableScan(): void
    {
        $json = <<<'JSON'
{
    "query_block": {
        "select_id": 1,
        "cost_info": {"query_cost": "0.35"},
        "table": {
            "table_name": "users",
            "access_type": "ALL",
            "rows_examined_per_scan": 1,
            "filtered": "100.00",
            "attached_condition": "users.status = 'active'"
        }
    }
}
JSON;
        $expected = <<<'EXPECTED'
Table scan
+- Table
   table           users
   rows            1
   filtered        100.00
   condition       users.status = 'active'
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);
        $this->assertSame($this->normalizeLineEndings($expected), $this->normalizeLineEndings($result));
    }

    /** @test */
    public function joinWithNestedLoop(): void
    {
        $json = <<<'JSON'
{
    "query_block": {
        "select_id": 1,
        "cost_info": {"query_cost": "0.70"},
        "grouping_operation": {
            "using_filesort": false,
            "nested_loop": [
                {
                    "table": {
                        "table_name": "film_actor",
                        "access_type": "ref",
                        "possible_keys": ["idx_fk_film_id"],
                        "key": "idx_fk_film_id",
                        "rows_examined_per_scan": 2
                    }
                },
                {
                    "table": {
                        "table_name": "film",
                        "access_type": "ALL",
                        "possible_keys": ["PRIMARY"],
                        "rows_examined_per_scan": 952
                    }
                }
            ]
        }
    }
}
JSON;
        $expected = <<<'EXPECTED'
JOIN
+- Index lookup
|  key             idx_fk_film_id
|  rows            2
|  +- Table
|     table           film_actor
+- Table scan
   rows            952
   +- Table
      table           film
      possible_keys   PRIMARY
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);
        $this->assertSame($this->normalizeLineEndings($expected), $this->normalizeLineEndings($result));
    }

    /** @test */
    public function tableWithSubqueries(): void
    {
        $json = <<<'JSON'
{
    "query_block": {
        "select_id": 1,
        "cost_info": {"query_cost": "0.35"},
        "table": {
            "table_name": "u",
            "access_type": "ALL",
            "rows_examined_per_scan": 1,
            "rows_produced_per_join": 1,
            "filtered": "100.00",
            "attached_condition": "(`test`.`u`.`status` = 'active')"
        },
        "select_list_subqueries": [
            {
                "dependent": true,
                "cacheable": false,
                "query_block": {
                    "select_id": 3,
                    "table": {
                        "table_name": "comments",
                        "access_type": "ref",
                        "key": "user_id",
                        "rows_examined_per_scan": 1,
                        "filtered": "100.00",
                        "using_index": true
                    }
                }
            },
            {
                "dependent": true,
                "cacheable": false,
                "query_block": {
                    "select_id": 2,
                    "table": {
                        "table_name": "orders",
                        "access_type": "ref",
                        "key": "idx_orders_user_id",
                        "rows_examined_per_scan": 1,
                        "filtered": "100.00",
                        "using_index": true
                    }
                }
            }
        ]
    }
}
JSON;
        $expected = <<<'EXPECTED'
Table scan
+- Table
|  table           u
|  rows            1
|  filtered        100.00
|  condition       (`test`.`u`.`status` = 'active')
+- Subquery (comments)
|  access_type     ref
|  key             user_id
|  rows            1
|  filtered        100.00
|  using_index     true
+- Subquery (orders)
   access_type     ref
   key             idx_orders_user_id
   rows            1
   filtered        100.00
   using_index     true
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);
        $this->assertSame($this->normalizeLineEndings($expected), $this->normalizeLineEndings($result));
    }

    /** @test */
    public function orderByWithFilesort(): void
    {
        $json = <<<'JSON'
{
    "query_block": {
        "select_id": 1,
        "cost_info": {"query_cost": "2.50"},
        "ordering_operation": {
            "using_filesort": true,
            "cost_info": {"sort_cost": "1.00"},
            "table": {
                "table_name": "orders",
                "access_type": "ALL",
                "rows_examined_per_scan": 10,
                "filtered": "100.00"
            }
        }
    }
}
JSON;
        $expected = <<<'EXPECTED'
Sort (using filesort)
sort_cost       1.00
+- Table scan
   +- Table
      table           orders
      rows            10
      filtered        100.00
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);
        $this->assertSame($this->normalizeLineEndings($expected), $this->normalizeLineEndings($result));
    }

    /** @test */
    public function fullTableScanWithCondition(): void
    {
        // 1) 全件スキャン（ALL）＋ 条件付きの例
        $json = <<<'JSON'
{
    "query_block":{
        "select_id":1,
        "cost_info":{"query_cost":"523.20"},
        "table":{
            "table_name":"posts",
            "access_type":"ALL",
            "rows_examined_per_scan":4902,
            "rows_produced_per_join":1633,
            "filtered":"33.33",
            "cost_info":{
                "read_cost":"359.82",
                "eval_cost":"163.38",
                "prefix_cost":"523.20",
                "data_read_per_join":"1M"
            },
            "used_columns":["id","user_id","title","content","status","view_count","created_at"],
            "attached_condition":"(`test`.`posts`.`view_count` > 1000)"
        }
    }
}
JSON;

        // 期待されるツリー
        $expected = <<<'EXPECTED'
Table scan
+- Table
   table           posts
   rows            4902
   filtered        33.33
   condition       (`test`.`posts`.`view_count` > 1000)
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);

        $this->assertSame(
            $this->normalizeLineEndings($expected),
            $this->normalizeLineEndings($result),
        );
    }

    /** @test */
    public function sortUsingFilesortOnAllScan(): void
    {
        // 2) ordering_operation + using_filesort の例
        $json = <<<'JSON'
{
    "query_block":{
        "select_id":1,
        "cost_info":{"query_cost":"523.20"},
        "ordering_operation":{
            "using_filesort":true,
            "table":{
                "table_name":"posts",
                "access_type":"ALL",
                "rows_examined_per_scan":4902,
                "rows_produced_per_join":490,
                "filtered":"10.00",
                "cost_info":{
                    "read_cost":"474.18",
                    "eval_cost":"49.02",
                    "prefix_cost":"523.20",
                    "data_read_per_join":"543K"
                },
                "used_columns":["id","user_id","title","content","status","view_count","created_at"],
                "attached_condition":"(`test`.`posts`.`status` = 'published')"
            }
        }
    }
}
JSON;

        $expected = <<<'EXPECTED'
Sort (using filesort)
+- Table scan
   +- Table
      table           posts
      rows            4902
      filtered        10.00
      condition       (`test`.`posts`.`status` = 'published')
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);

        $this->assertSame(
            $this->normalizeLineEndings($expected),
            $this->normalizeLineEndings($result),
        );
    }

    /** @test */
    public function joinWithNestedLoopIndexScanAndRef(): void
    {
        // 3) grouping_operation.nested_loop の例
        //    access_type=index → "Index scan"
        //    access_type=ref   → "Index lookup"
        $json = <<<'JSON'
{
    "query_block":{
        "select_id":1,
        "cost_info":{"query_cost":"1124.71"},
        "grouping_operation":{
            "using_filesort":false,
            "nested_loop":[
                {
                    "table":{
                        "table_name":"p",
                        "access_type":"index",
                        "possible_keys":["PRIMARY","idx_posts_user_id"],
                        "key":"PRIMARY",
                        "used_key_parts":["id"],
                        "key_length":"4",
                        "rows_examined_per_scan":4902,
                        "rows_produced_per_join":490,
                        "filtered":"10.00",
                        "cost_info":{
                            "read_cost":"474.18",
                            "eval_cost":"49.02",
                            "prefix_cost":"523.20",
                            "data_read_per_join":"543K"
                        },
                        "used_columns":["id","user_id","title","content","status","view_count","created_at"],
                        "attached_condition":"(`test`.`p`.`status` = 'published')"
                    }
                },
                {
                    "table":{
                        "table_name":"c",
                        "access_type":"ref",
                        "possible_keys":["idx_comments_post_id"],
                        "key":"idx_comments_post_id",
                        "used_key_parts":["post_id"],
                        "key_length":"5",
                        "ref":["test.p.id"],
                        "rows_examined_per_scan":2,
                        "rows_produced_per_join":1106,
                        "filtered":"100.00",
                        "using_index":true,
                        "cost_info":{
                            "read_cost":"490.88",
                            "eval_cost":"110.64",
                            "prefix_cost":"1124.71",
                            "data_read_per_join":"34K"
                        },
                        "used_columns":["id","post_id"]
                    }
                }
            ]
        }
    }
}
JSON;

        $expected = <<<'EXPECTED'
JOIN
+- Index scan
|  key             PRIMARY
|  rows            4902
|  filtered        10.00
|  +- Table
|     table           p
|     condition       (`test`.`p`.`status` = 'published')
+- Index lookup
   key             idx_comments_post_id
   rows            2
   filtered        100.00
   +- Table
      table           c
EXPECTED;

        $tree = $this->parser->parse($json);
        $result = $this->visualizer->toString($tree);

        $this->assertSame(
            $this->normalizeLineEndings($expected),
            $this->normalizeLineEndings($result),
        );
    }

    private function normalizeLineEndings(string $string): string
    {
        return str_replace(["\r\n", "\r"], "\n", trim($string));
    }
}

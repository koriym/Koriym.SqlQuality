<?php

namespace Koriym\SqlQuality;

use const JSON_BIGINT_AS_STRING;

require __DIR__ . '/vendor/autoload.php';


// EXPLAINのJSON出力を用意
$explainJson = <<<JSON_BIGINT_AS_STRING
{"query_block":{"select_id":1,"cost_info":{"query_cost":"0.35"},"table":{"table_name":"u","access_type":"ALL","rows_examined_per_scan":1,"rows_produced_per_join":1,"filtered":"100.00","cost_info":{"read_cost":"0.25","eval_cost":"0.10","prefix_cost":"0.35","data_read_per_join":"2K"},"used_columns":["id","name","email","status","created_at","updated_at"],"attached_condition":"(`test`.`u`.`status` = 'active')"},"select_list_subqueries":[{"dependent":true,"cacheable":false,"query_block":{"select_id":3,"cost_info":{"query_cost":"0.35"},"table":{"table_name":"comments","access_type":"ref","possible_keys":["user_id"],"key":"user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.u.id"],"rows_examined_per_scan":1,"rows_produced_per_join":1,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"0.25","eval_cost":"0.10","prefix_cost":"0.35","data_read_per_join":"32"},"used_columns":["user_id"]}}},{"dependent":true,"cacheable":false,"query_block":{"select_id":2,"cost_info":{"query_cost":"0.35"},"table":{"table_name":"orders","access_type":"ref","possible_keys":["idx_orders_user_id"],"key":"idx_orders_user_id","used_key_parts":["user_id"],"key_length":"5","ref":["test.u.id"],"rows_examined_per_scan":1,"rows_produced_per_join":1,"filtered":"100.00","using_index":true,"cost_info":{"read_cost":"0.25","eval_cost":"0.10","prefix_cost":"0.35","data_read_per_join":"304"},"used_columns":["user_id"]}}}]}}
JSON_BIGINT_AS_STRING;

$visualizer = new ExplainTreeVisualizer();
$parser = new ExplainParser();
$explainer = new ExplainExplainer();

$tree = $parser->parse($explainJson);
echo $visualizer->toString($tree);
echo $explainer->explain($tree);

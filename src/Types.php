<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use stdClass;

/**
 * @psalm-type ExplainCostInfo = array{
 *   query_cost?: float,
 *   read_cost?: float,
 *   eval_cost?: float,
 *   sort_cost?: float,
 *   tmp_table_cost?: float
 * }
 * @psalm-type ExplainTable = array{
 *   table_name: string,
 *   access_type: string,
 *   possible_keys?: list<string>,
 *   key?: string,
 *   used_key_parts?: list<string>,
 *   ref?: list<string>,
 *   rows_examined_per_scan?: int|numeric-string,
 *   rows_produced_per_join?: int|numeric-string,
 *   filtered?: float|numeric-string,
 *   using_join_buffer?: string,
 *   index_condition?: string,
 *   attached_condition?: string,
 *   backward_index_scan?: bool,
 *   cost_info?: ExplainCostInfo,
 *   using_temporary_table?: bool,
 *   using_filesort?: bool,
 *   using_index?: bool,
 *   update?: bool,
 *   used_columns?: list<string>,
 *   materialized_from_subquery?: array<string, mixed>,
 *   table_function?: string
 * }
 * @psalm-type DuplicatesRemovalOperation = array{
 *   using_temporary_table?: bool,
 *   using_filesort?: bool,
 *   table?: ExplainTable
 * }
 * @psalm-type ExplainOperation = array{
 *   using_temporary_table?: bool,
 *   using_filesort?: bool,
 *   cost_info?: ExplainCostInfo,
 *   table?: ExplainTable,
 *   nested_loop?: array<array{table: ExplainTable}>,
 *   duplicates_removal?: DuplicatesRemovalOperation
 * }
 * @psalm-type ExplainQueryBlock = array{
 *   select_id: int,
 *   cost_info?: ExplainCostInfo,
 *   table?: ExplainTable,
 *   grouping_operation?: ExplainOperation,
 *   ordering_operation?: ExplainOperation,
 *   duplicates_removal?: DuplicatesRemovalOperation,
 *   select_list_subqueries?: array<array{
 *     query_block: array{table: ExplainTable}
 *   }>,
 *   nested_loop?: ExplainOperation,
 *   union_result?: array<mixed>
 * }
 * @psalm-type ExplainNode = array<string, mixed> Intentionally loose type for recursive traversal of arbitrary EXPLAIN JSON structures
 * @psalm-type ExplainTableAccess = array{
 *   path: list<array-key>,
 *   table: ExplainTable
 * }
 * @psalm-type ExplainResult = array{
 *   query_block: ExplainQueryBlock,
 *   analyze_result: array<string, mixed>
 * }
 * @psalm-type ShowWarning = array{
 *   Level: string,
 *   Code: int,
 *   Message: string
 * }
 * @psalm-type ShowWarnings = list<ShowWarning>
 * @psalm-type OptimizerTraceTable = array{
 *   table: string,
 *   select: int,
 *   range_analysis?: array<string, mixed>,
 *   considered_access_paths?: list<array<string, mixed>>,
 *   rechecking_index_usage?: array<string, mixed>
 * }
 * @psalm-type OptimizerTraceExcerpt = array{
 *   tables: list<OptimizerTraceTable>,
 *   transformations: list<array<string, mixed>>,
 *   condition_processing: list<array<string, mixed>>
 * }
 * @psalm-type AnalyzeNode = array{
 *   depth: int,
 *   operation: string,
 *   estimated_cost: float|null,
 *   estimated_rows: float|null,
 *   actual_time_first: float|null,
 *   actual_time_last: float|null,
 *   actual_rows: float|null,
 *   loops: int|null,
 *   never_executed: bool
 * }
 * @psalm-type WarningType =
 * 'CartesianProduct'
 * | 'DeepOffset'
 * | 'DependentSubquery'
 * | 'EstimateDivergence'
 * | 'ExcessiveDerivedTables'
 * | 'FunctionInvalidatesIndex'
 * | 'FullTableScan'
 * | 'ImplicitTypeConversion'
 * | 'IneffectiveJoin'
 * | 'IneffectiveLikePattern'
 * | 'IneffectiveRangeScan'
 * | 'IneffectiveSort'
 * | 'IneffectiveUnion'
 * | 'LowCardinalityIndex'
 * | 'MultiTableUpdate'
 * | 'OrderByRand'
 * | 'TemporaryTableGrouping'
 * | 'UnnecessaryDistinct'
 * @psalm-type WarningSeverity = 'Info'|'Warning'|'Critical'
 * @psalm-type Suggestion = array{kind: 'index'|'rewrite'|'review', description: string, ddl?: string, sql?: string}
 * @psalm-type DetectedWarning = array{
 *   id: string,
 *   type: WarningType,
 *   message: string,
 *   documentation: string,
 *   severity: WarningSeverity,
 *   confidence: float,
 *   evidence: array<string, mixed>,
 *   suggestion: Suggestion|null
 * }
 * @psalm-type SchemaColumn = array{
 *   COLUMN_NAME: string,
 *   DATA_TYPE: string,
 *   COLUMN_TYPE: string,
 *   IS_NULLABLE: string,
 *   COLUMN_KEY: string,
 *   COLUMN_DEFAULT: string|null,
 *   EXTRA: string
 * }
 * @psalm-type SchemaIndex = array{
 *   INDEX_NAME: string,
 *   COLUMN_NAME: string,
 *   NON_UNIQUE: int,
 *   SEQ_IN_INDEX: int,
 *   CARDINALITY: int|null
 * }
 * @psalm-type TableStatus = array{
 *   table_rows: int|null,
 *   data_length: int|null,
 *   index_length: int|null,
 *   auto_increment: int|null,
 *   create_time: string|null,
 *   update_time: string|null
 * }
 * @psalm-type SchemaInfo = array{
 *   columns: list<SchemaColumn>,
 *   indexes: list<SchemaIndex>,
 *   status: TableStatus
 * }
 * @psalm-type SqlParams = array<string, array<string, mixed>>
 * @psalm-type SqlClassification = array{
 *   kind: string,
 *   is_explainable: bool,
 *   is_read_only_select: bool,
 *   reason: string
 * }
 * @psalm-type AnalysisResult = array{
 *   executed: bool,
 *   skipped_reason: string|null,
 *   issues: list<DetectedWarning>,
 *   explain_result: ExplainResult,
 *   cost: float,
 *   execution_time: float|null,
 *   without_optimizer: array{cost: float, issues: list<DetectedWarning>}
 * }
 * @psalm-type AnalysisRun = array{
 *   results: array<string, AnalysisResult>,
 *   skipped: array<string, string>
 * }
 * @psalm-type OptimizerImpact = array{
 *   cost_without_optimizer: float,
 *   cost_reduction_percent: float,
 *   hidden_issues: list<string>
 * }
 * @psalm-type JsonReportQuery = array{
 *   executed: bool,
 *   skipped_reason: string|null,
 *   cost: float,
 *   execution_time_ms: float|null,
 *   issues: list<DetectedWarning>,
 *   optimizer_impact: OptimizerImpact
 * }
 * @psalm-type BaselineIssue = array{file: string, id: string, severity: WarningSeverity}
 * @psalm-type BaselineDiff = array{
 *   new: list<BaselineIssue>,
 *   resolved: list<BaselineIssue>,
 *   persisting: list<BaselineIssue>,
 *   unverified: list<string>
 * }
 * @psalm-type JsonReport = array{
 *   summary: array{
 *     total_queries: int,
 *     analyzed: int,
 *     skipped: int,
 *     total_cost: float,
 *     avg_cost: float,
 *     total_issues: int,
 *     issues_by_severity: array<WarningSeverity, int>
 *   },
 *   queries: array<string, JsonReportQuery>|stdClass,
 *   skipped: array<string, string>|stdClass,
 *   baseline?: BaselineDiff
 * }
 */
final class Types
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}

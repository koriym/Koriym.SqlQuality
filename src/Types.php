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
 *   materialized_from_subquery?: array<string, mixed>
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
 * @psalm-type WarningMessages = array{
 *   CartesianProduct: string,
 *   DeepOffset: string,
 *   DependentSubquery: string,
 *   EstimateDivergence: string,
 *   ExcessiveDerivedTables: string,
 *   FunctionInvalidatesIndex: string,
 *   FullTableScan: string,
 *   ImplicitTypeConversion: string,
 *   IneffectiveJoin: string,
 *   IneffectiveLikePattern: string,
 *   IneffectiveRangeScan: string,
 *   IneffectiveSort: string,
 *   IneffectiveUnion: string,
 *   LowCardinalityIndex: string,
 *   MultiTableUpdate: string,
 *   OrderByRand: string,
 *   TemporaryTableGrouping: string,
 *   UnnecessaryDistinct: string
 * }
 * @psalm-type Warning = array{
 *   message: string,
 *   detector: Detector\DetectorInterface
 * }
 * @psalm-type WarningSeverity = 'Info'|'Warning'|'Critical'
 * @psalm-type Suggestion = array{kind: 'index'|'rewrite'|'review', description: string, ddl?: string, sql?: string}
 * @psalm-type DetectedWarning = array{
 *   type: WarningType,
 *   message: string,
 *   documentation: string,
 *   severity: WarningSeverity,
 *   confidence: float,
 *   detector: class-string<Detector\DetectorInterface>,
 *   evidence: array<string, mixed>,
 *   suggestion: Suggestion|null
 * }
 * @psalm-type TreeNodeAttributes = array<string, string>
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
 *   mode: 'wd',
 *   executed: bool,
 *   skipped_reason: string|null,
 *   issues: list<DetectedWarning>,
 *   explain_result: ExplainResult,
 *   ai_suggestions: string,
 *   cost: float,
 *   execution_time: float,
 *   optimizer_comparison: array{
 *      with_optimizer: array<array-key, mixed>,
 *      without_optimizer: array<array-key, mixed>,
 *      difference: array{
 *          cost_percent: float,
 *          time_percent: float
 *      }
 *   }
 * }
 * @psalm-type AnalysisRun = array{
 *   results: array<string, AnalysisResult>,
 *   skipped: array<string, string>
 * }
 * @psalm-type JsonReportQuery = array{
 *   mode: 'wd',
 *   executed: bool,
 *   skipped_reason: string|null,
 *   cost: float,
 *   execution_time_ms: float|null,
 *   issues: list<DetectedWarning>,
 *   optimizer_impact: array{cost_reduction_percent: float}
 * }
 * @psalm-type JsonReport = array{
 *   summary: array{
 *     total_queries: int,
 *     analyzed: int,
 *     skipped: int,
 *     avg_cost: float,
 *     total_issues: int,
 *     issues_by_severity: array<WarningSeverity, int>
 *   },
 *   queries: array<string, JsonReportQuery>|stdClass,
 *   skipped: array<string, string>|stdClass
 * }
 * @psalm-type QueryStatisticsResult = array{
 *   total_count: int,
 *   avg_cost: float,
 *   std_dev: float
 * }
 * @psalm-type TreeOperation = array{
 *    cost_info?: array{
 *      sort_cost?: float,
 *      tmp_table_cost?: float
 *    },
 *    using_temporary_table?: bool,
 *    using_filesort?: bool,
 *    table?: array{
 *      table_name: string,
 *      rows_examined_per_scan: int,
 *      filtered: float,
 *      attached_condition?: string
 *    }
 *  }
 * @psalm-type ExplainOperationResult = array{
 *    table?: array{
 *      table_name: string,
 *      rows_examined_per_scan: int,
 *      filtered: float,
 *      attached_condition?: string,
 *      access_type: string
 *    },
 *    using_filesort?: bool,
 *    using_temporary_table?: bool,
 *    cost_info?: array{sort_cost?: float}
 *  }
 * @psalm-type QueryCost = array{
 *    total_cost: float,
 *    details: array{
 *      rows_examined: int,
 *      temporary_tables: bool,
 *      filesort: bool,
 *      full_scan: bool
 *    }
 *  }
 * @psalm-type ExplainTreeOperation = array{
 *    table?: array{
 *      table_name: string,
 *      rows_examined_per_scan: int,
 *      filtered: float,
 *      attached_condition?: string
 *    },
 *    using_filesort?: bool,
 *    using_temporary_table?: bool,
 *    cost_info?: array{sort_cost?: float}
 *  }
 * @psalm-type QueryResult = array{
 *    cost: float,
 *    explain_result: ExplainResult
 *    issues: list<string>
 *  }
 * @psalm-type QueryResults = array<string, QueryResult>
 * @psalm-type StatisticsResult = array{
 *    total_count: int,
 *    avg_cost: float,
 *    std_dev: float
 *  }
 * @psalm-type QueryBlock = array<mixed>
 * @psalm-type AnalysisWithSettingsResult = array{
 *   mode: 'wd',
 *   executed: bool,
 *   skipped_reason: string|null,
 *   issues: list<DetectedWarning>,
 *   explain_result: ExplainResult,
 *   ai_suggestions: string,
 *   cost: float,
 *   execution_time: float
 * }
 */
final class Types
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}

<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

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
 *   possible_keys?: string|null,
 *   key?: string|null,
 *   rows: int,
 *   rows_examined_per_scan: int,
 *   filtered: float,
 *   attached_condition?: string,
 *   cost_info?: ExplainCostInfo,
 *   using_temporary_table?: bool,
 *   using_filesort?: bool,
 *   using_index?: bool
 * }
 * @psalm-type ExplainOperation = array{
 *   using_temporary_table?: bool,
 *   using_filesort?: bool,
 *   cost_info?: ExplainCostInfo,
 *   table?: ExplainTable,
 *   nested_loop?: array<array{table: ExplainTable}>
 * }
 * @psalm-type ExplainQueryBlock = array{
 *   select_id: int,
 *   cost_info?: ExplainCostInfo,
 *   table?: ExplainTable,
 *   grouping_operation?: ExplainOperation,
 *   ordering_operation?: ExplainOperation,
 *   select_list_subqueries?: array<array{
 *     query_block: array{table: ExplainTable}
 *   }>,
 *   nested_loop?: ExplainOperation
 * }
 * @psalm-type ExplainResult = array{
 *   query_block: ExplainQueryBlock
 * }
 * @psalm-type ShowWarning = array{
 *   Level: string,
 *   Code: int,
 *   Message: string
 * }
 * @psalm-type ShowWarnings = list<ShowWarning>
 * @psalm-type WarningType = 'FullTableScan'|'IneffectiveJoin'|'FunctionInvalidatesIndex'|'IneffectiveLikePattern'|'ImplicitTypeConversion'|'IneffectiveSort'|'TemporaryTableGrouping'
 * @psalm-type WarningMessages = array{
 *   FullTableScan: string,
 *   IneffectiveJoin: string,
 *   FunctionInvalidatesIndex: string,
 *   IneffectiveLikePattern: string,
 *   ImplicitTypeConversion: string,
 *   IneffectiveSort: string,
 *   TemporaryTableGrouping: string,
 *   IneffectiveRangeScan: string,
 *   ExcessiveDerivedTables: string,
 *   IneffectiveUnion: string,
 *   UnnecessaryDistinct: string,
 *   MultiTableUpdate: string,
 *   LowCardinalityIndex: string
 * }
 * @psalm-type WarningPattern = array{
 *   explain?: array<string, mixed>,
 *   warnings?: list<string>
 * }
 * @psalm-type Warning = array{
 *   message: string,
 *   pattern: WarningPattern
 * }
 * @psalm-type DetectedWarning = array{
 *   type: WarningType,
 *   message: string,
 *   documentation: string
 * }
 * @psalm-type TreeNodeAttributes = array<string, string>
 * @psalm-type SchemaColumn = array{
 *   column_name: string,
 *   data_type: string,
 *   column_type: string,
 *   is_nullable: string,
 *   column_key: string,
 *   column_default: string|null,
 *   extra: string
 * }
 * @psalm-type SchemaIndex = array{
 *   index_name: string,
 *   column_name: string,
 *   non_unique: string,
 *   seq_in_index: string,
 *   cardinality: string|null
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
 * @psalm-type AnalysisResult = array{
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
 */
final class Types
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}

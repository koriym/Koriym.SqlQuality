<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use ArrayObject;
use PDO;

/**
 * Type definitions for Koriym\SqlQuality
 *
 * @phpcs:disable SlevomatCodingStandard.Commenting.DocCommentSpacing
 *
 * Domain Types
 * @psalm-type WarningType = 'FullTableScan'|'IneffectiveJoin'|'FunctionInvalidatesIndex'|'IneffectiveLikePattern'|'ImplicitTypeConversion'|'IneffectiveSort'|'TemporaryTableGrouping'
 * @psalm-type QueryLevel = 'Very High (> μ + 2σ)'|'High (μ + σ to μ + 2σ)'|'Medium (μ ± σ)'|'Low (< μ)'
 * @psalm-type SqlFile = non-empty-string
 * @psalm-type TableName = non-empty-string
 *
 * Tree Types
 * @psalm-type TreeNodeText = string
 * @psalm-type TreeNodeAttributes = array<string, string>
 * @psalm-type TreeNodeChildren = array<TreeNode>
 *
 * Explain Tree Types
 * @psalm-type ExplainTreeBranch = string
 * @psalm-type ExplainTreePipe = string
 * @psalm-type ExplainTreeSpace = string
 * @psalm-type ExplainTreeParentPipes = array<int, bool>
 *
 * Query Statistics Types
 * @psalm-type QueryStatisticsResult = array{
 *   total_count: int,
 *   avg_cost: float,
 *   std_dev: float
 * }
 * @psalm-type QueryResults = array<string, array{
 *   cost: float,
 *   issues: array<string>,
 *   explain_result: array
 * }>
 *
 * Explain Analyzer Types
 * @psalm-type WarningMessages = array{
 *   FullTableScan: string,
 *   IneffectiveJoin: string,
 *   FunctionInvalidatesIndex: string,
 *   IneffectiveLikePattern: string,
 *   ImplicitTypeConversion: string,
 *   IneffectiveSort: string,
 *   TemporaryTableGrouping: string
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
 * @psalm-type ShowWarning = array{
 *   Level: string,
 *   Code: int,
 *   Message: string
 * }
 *
 * Explain Result Types
 * @psalm-type QueryCost = array{
 *   total_cost: float,
 *   details: array{
 *     rows_examined: int,
 *     temporary_tables: bool,
 *     filesort: bool,
 *     full_scan: bool
 *   }
 * }
 * @psalm-type TreeNodeData = array{
 *   text: string,
 *   attributes: array<string, string|float|null>,
 *   children: list<TreeNodeData>
 * }
 * @psalm-type ExplainResult = array{
 *   query_block: array{
 *     select_id: int,
 *     table?: array{
 *       table_name: string,
 *       access_type: string,
 *       possible_keys?: string|null,
 *       key?: string|null,
 *       rows: int,
 *       rows_examined_per_scan: int,
 *       filtered: float,
 *       attached_condition?: string,
 *       cost_info?: array{
 *         read_cost: float,
 *         eval_cost: float,
 *         sort_cost?: float
 *       },
 *       using_temporary_table?: bool,
 *       using_filesort?: bool
 *     },
 *     ordering_operation?: array{
 *       using_filesort: bool,
 *       table: array,
 *       cost_info?: array{
 *         sort_cost: float
 *       }
 *     },
 *     grouping_operation?: array{
 *       using_temporary_table: bool,
 *       using_filesort: bool,
 *       table: array,
 *       cost_info?: array{
 *         tmp_table_cost: float,
 *         sort_cost?: float
 *       }
 *     }
 *   }
 * }
 *
 * Schema Types
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
 *
 * SQL File Analysis Types
 * @psalm-type SqlParams = array<string, array<string, mixed>>
 * @psalm-type AnalysisResult = array{
 *   issues: list<DetectedWarning>,
 *   explain_result: ExplainResult,
 *   ai_suggestions: string,
 *   cost: float
 * }
 * @psalm-type AnalysisResults = array<string, AnalysisResult>
 * @psalm-type ShowWarnings = list<ShowWarning>
 */
final class Types
{
    /** @codeCoverageIgnore */
    private function __construct()
    {
    }
}

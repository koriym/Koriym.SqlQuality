<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;

use function array_map;
use function assert;
use function implode;
use function is_array;

final class OptimizerSettings
{
    private const OPTIMIZER_OPTIONS = [
        'index_merge',
        'index_merge_union',
        'index_merge_sort_union',
        'index_merge_intersection',
        'engine_condition_pushdown',
        'index_condition_pushdown',
        'mrr',
        'mrr_cost_based',
        'block_nested_loop',
        'batched_key_access',
        'materialization',
        'semijoin',
        'loosescan',
        'firstmatch',
        'duplicateweedout',
        'subquery_materialization_cost_based',
        'use_index_extensions',
        'condition_fanout_filter',
        'derived_merge',
    ];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function saveCurrentSettings(): array
    {
        $stmt = $this->pdo->query('SELECT @@optimizer_switch');

        $settings =  $stmt->fetch(PDO::FETCH_ASSOC);
        assert(is_array($settings));

        return $settings;
    }

    public function restore(array $settings): void
    {
        $this->pdo->exec("SET optimizer_switch = '{$settings['@@optimizer_switch']}'");
    }

    public function disableAll(): void
    {
        $options = array_map(
            static fn (string $option) => "{$option}=off",
            self::OPTIMIZER_OPTIONS
        );
        $optionString = implode(',', $options);
        $this->pdo->exec("SET optimizer_switch = '$optionString'");
    }

    public function enableAll(): void
    {
        $options = array_map(
            static fn (string $option) => "{$option}=on",
            self::OPTIMIZER_OPTIONS
        );
        $optionString = implode(',', $options);
        $this->pdo->exec("SET optimizer_switch = '$optionString'");
    }
}

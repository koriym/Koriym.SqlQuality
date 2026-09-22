<?php

declare(strict_types=1);

namespace Koriym\SqlQuality\Detector;

use Koriym\SqlQuality\ExplainWalker;
use Koriym\SqlQuality\QueryContext;
use Koriym\SqlQuality\Types;
use Override;

use function array_map;
use function count;
use function is_array;

/** @psalm-import-type ExplainNode from Types */
final class ExcessiveDerivedTablesDetector implements DetectorInterface
{
    private const MIN_DERIVED_TABLES = 3;

    #[Override]
    public function detect(QueryContext $context): array
    {
        $derived = $this->materializedDerivedTables($context->explain);
        if (count($derived) < self::MIN_DERIVED_TABLES) {
            return [];
        }

        $tables = [];
        foreach ($derived as $name => $subquery) {
            $tables[$name] = array_map(
                static fn (array $table): string => (string) $table['table_name'],
                (new ExplainWalker())->tables($subquery),
            );
        }

        return [new Finding(['derived_count' => count($derived), 'tables' => $tables])];
    }

    /**
     * @param ExplainNode $node
     *
     * @return array<string, ExplainNode> derived table name (`<derivedN>`) => its materialized_from_subquery node
     */
    private function materializedDerivedTables(array $node): array
    {
        $derived = [];
        if (isset($node['table_name'], $node['materialized_from_subquery']['using_temporary_table']) && $node['materialized_from_subquery']['using_temporary_table'] === true) {
            /** @var ExplainNode $subquery */
            $subquery = $node['materialized_from_subquery'];
            $derived[(string) $node['table_name']] = $subquery;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $derived += $this->materializedDerivedTables($value);
            }
        }

        return $derived;
    }
}

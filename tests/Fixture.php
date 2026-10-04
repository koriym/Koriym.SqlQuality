<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use function basename;
use function file_get_contents;
use function glob;
use function json_decode;
use function sort;

use const JSON_THROW_ON_ERROR;
use const SORT_STRING;

final class Fixture
{
    public static function load(string $name): QueryContext
    {
        $json = (string) file_get_contents(__DIR__ . '/fixtures/' . basename($name, '.sql') . '.json');
        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return QueryContext::fromArray($data);
    }

    /** @return list<string> */
    public static function names(): array
    {
        $names = [];
        foreach ((array) glob(__DIR__ . '/fixtures/*.json') as $file) {
            $names[] = basename((string) $file, '.json') . '.sql';
        }

        sort($names, SORT_STRING);

        return $names;
    }
}

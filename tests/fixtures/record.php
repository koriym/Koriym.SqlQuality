#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use PDO;
use Throwable;

use function array_map;
use function array_slice;
use function basename;
use function dirname;
use function file_put_contents;
use function fwrite;
use function getenv;
use function glob;
use function json_encode;

use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const STDERR;
use const STDOUT;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$sqlDir = dirname(__DIR__) . '/sql';
/** @var array<string, array<string, mixed>> $params */
$params = require dirname(__DIR__) . '/params/sql_params.php';

$names = array_slice($argv, 1);
if ($names === []) {
    $names = array_map(static fn (string $file): string => basename($file), (array) glob($sqlDir . '/*.sql'));
}

$pdo = new PDO(
    (string) (getenv('SQL_QUALITY_DSN') ?: 'mysql:host=127.0.0.1;dbname=test'),
    (string) (getenv('SQL_QUALITY_USER') ?: 'root'),
    (string) getenv('SQL_QUALITY_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$analyzer = new SqlFileAnalyzer($pdo, new ExplainAnalyzer(), $sqlDir, new AIQueryAdvisor(''));

foreach ($names as $name) {
    if ($name === 'schema.sql') {
        continue;
    }

    try {
        $context = $analyzer->queryContext($name, $params[$name] ?? []);
    } catch (Throwable $e) {
        fwrite(STDERR, "skip {$name}: {$e->getMessage()}\n");
        continue;
    }

    $json = json_encode($context->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents(__DIR__ . '/' . basename($name, '.sql') . '.json', $json . "\n");
    fwrite(STDOUT, "recorded {$name}\n");
}

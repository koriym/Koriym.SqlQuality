<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use JsonException;
use Koriym\SqlQuality\Exception\InvalidBaseline;

use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function in_array;
use function is_array;
use function is_file;
use function is_string;
use function json_decode;
use function sort;
use function strcmp;
use function usort;

use const JSON_THROW_ON_ERROR;

/**
 * The issues of an earlier `analyze` JSON report, to tell what a new run added and what it fixed.
 *
 * @psalm-import-type AnalysisRun from Types
 * @psalm-import-type BaselineDiff from Types
 * @psalm-import-type BaselineIssue from Types
 * @psalm-import-type WarningSeverity from Types
 */
final class Baseline
{
    private const SEVERITIES = ['Critical', 'Warning', 'Info'];

    /** @param array<string, array<string, WarningSeverity>> $issues file => id => severity */
    private function __construct(private readonly array $issues)
    {
    }

    /** @throws InvalidBaseline */
    public static function fromFile(string $path): self
    {
        if (! is_file($path)) {
            throw new InvalidBaseline("Baseline file not found: {$path}");
        }

        $json = file_get_contents($path);
        if ($json === false) {
            throw new InvalidBaseline("Baseline file could not be read: {$path}");
        }

        try {
            $report = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidBaseline("Baseline file is not valid JSON: {$path}: {$e->getMessage()}");
        }

        if (! is_array($report)) {
            throw new InvalidBaseline("Baseline file is not an analyze report: {$path}");
        }

        return self::fromReport($report);
    }

    /**
     * @param array<array-key, mixed> $report a decoded `analyze` JSON report
     *
     * @throws InvalidBaseline
     */
    public static function fromReport(array $report): self
    {
        $queries = $report['queries'] ?? null;
        if (! is_array($queries)) {
            throw new InvalidBaseline('Baseline has no queries object; pass the JSON output of `sql-quality analyze`');
        }

        $issues = [];
        foreach ($queries as $file => $query) {
            if (! is_string($file) || ! is_array($query) || ! is_array($query['issues'] ?? null)) {
                throw new InvalidBaseline('Baseline query entries must carry an issues list');
            }

            $issues[$file] = [];
            /** @var mixed $issue */
            foreach ($query['issues'] as $issue) {
                if (! is_array($issue) || ! is_string($issue['id'] ?? null) || ! in_array($issue['severity'] ?? null, self::SEVERITIES, true)) {
                    throw new InvalidBaseline("Baseline issue of {$file} has no id or severity; it was written by an older version");
                }

                /** @var WarningSeverity $severity */
                $severity = $issue['severity'];
                $issues[$file][$issue['id']] = $severity;
            }
        }

        return new self($issues);
    }

    /**
     * new: issues the run has and the baseline does not. resolved: issues of a file the run analyzed
     * that the run no longer has. persisting: issues both have. unverified: baseline files the run did
     * not analyze (skipped or absent); their issues are neither resolved nor persisting.
     *
     * @param AnalysisRun $run
     *
     * @return BaselineDiff
     */
    public function diff(array $run): array
    {
        $new = [];
        $resolved = [];
        $persisting = [];
        foreach ($run['results'] as $file => $result) {
            $current = [];
            foreach ($result['issues'] as $issue) {
                $current[$issue['id']] = $issue['severity'];
                $entry = ['file' => $file, 'id' => $issue['id'], 'severity' => $issue['severity']];
                if (isset($this->issues[$file][$issue['id']])) {
                    $persisting[] = $entry;
                    continue;
                }

                $new[] = $entry;
            }

            foreach ($this->issues[$file] ?? [] as $id => $severity) {
                if (array_key_exists($id, $current)) {
                    continue;
                }

                $resolved[] = ['file' => $file, 'id' => $id, 'severity' => $severity];
            }
        }

        $unverified = [];
        foreach (array_keys($this->issues) as $file) {
            if (array_key_exists($file, $run['results'])) {
                continue;
            }

            $unverified[] = $file;
        }

        sort($unverified);

        return [
            'new' => self::sorted($new),
            'resolved' => self::sorted($resolved),
            'persisting' => self::sorted($persisting),
            'unverified' => $unverified,
        ];
    }

    /**
     * @param list<BaselineIssue> $issues
     *
     * @return list<BaselineIssue>
     */
    private static function sorted(array $issues): array
    {
        usort($issues, static fn (array $a, array $b): int => strcmp($a['file'] . "\0" . $a['id'], $b['file'] . "\0" . $b['id']));

        return $issues;
    }
}

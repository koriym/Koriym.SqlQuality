<?php

declare(strict_types=1);

namespace Koriym\SqlQuality;

use Koriym\SqlQuality\Exception\InvalidSeverityThreshold;

use function strtolower;

/** @psalm-import-type AnalysisRun from Types */
final class SeverityThreshold
{
    private const LEVELS = ['info' => 0, 'warning' => 1, 'critical' => 2];
    private const SEVERITIES = ['Info' => 0, 'Warning' => 1, 'Critical' => 2];

    private readonly int $level;

    public function __construct(string $level)
    {
        $normalized = strtolower($level);
        if (! isset(self::LEVELS[$normalized])) {
            throw new InvalidSeverityThreshold($level);
        }

        $this->level = self::LEVELS[$normalized];
    }

    /** @param AnalysisRun $run */
    public function isMetBy(array $run): bool
    {
        foreach ($run['results'] as $result) {
            foreach ($result['issues'] as $issue) {
                if (self::SEVERITIES[$issue['severity']] >= $this->level) {
                    return true;
                }
            }
        }

        return false;
    }
}

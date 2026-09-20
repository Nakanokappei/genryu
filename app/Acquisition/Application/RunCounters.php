<?php

namespace App\Acquisition\Application;

/**
 * Per-run tallies stored in acquisition_runs.counters. Keys are fixed so
 * dashboards and tests can rely on them; a run's final status is derived
 * from these numbers (ADR-0004 "partial failure").
 */
final class RunCounters
{
    public const FETCHED = 'fetched';

    public const NEW = 'new';

    public const REVISED = 'revised';

    public const UNCHANGED = 'unchanged';

    public const FAILED = 'failed';

    public const QUALITY_FAILED = 'quality_failed';

    public const REPROCESSED = 'reprocessed';

    public const SKIPPED = 'skipped';

    /** @var array<string, int> */
    private array $counts = [
        self::FETCHED => 0,
        self::NEW => 0,
        self::REVISED => 0,
        self::UNCHANGED => 0,
        self::FAILED => 0,
        self::QUALITY_FAILED => 0,
        self::REPROCESSED => 0,
        self::SKIPPED => 0,
    ];

    public function increment(string $key, int $by = 1): void
    {
        $this->counts[$key] = ($this->counts[$key] ?? 0) + $by;
    }

    public function get(string $key): int
    {
        return $this->counts[$key] ?? 0;
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }
}

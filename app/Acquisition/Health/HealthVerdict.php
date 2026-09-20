<?php

namespace App\Acquisition\Health;

use App\Acquisition\Domain\Enums\HealthStatus;

/**
 * The health evaluator's conclusion for one monitoring run.
 */
final readonly class HealthVerdict
{
    /**
     * @param  list<array{kind: string, severity: string, detail: array<string, mixed>}>  $evidence
     */
    public function __construct(
        public bool $drift,
        public array $evidence,
        public HealthStatus $from,
        public HealthStatus $to,
        public bool $discoveryQueued = false,
    ) {}
}

<?php

namespace App\Acquisition\Tools;

use Illuminate\Support\Str;

/**
 * Who is calling a Tool and on behalf of which run. Every Tool invocation
 * carries this so the audit trail and every error can be traced back to a
 * run (plan §7).
 */
final readonly class ToolContext
{
    public function __construct(
        public int $runId,
        public string $correlationId,
    ) {}

    /**
     * Context for a run with a freshly generated correlation ID.
     */
    public static function forRun(int $runId): self
    {
        return new self($runId, (string) Str::uuid());
    }
}

<?php

namespace App\Acquisition\Domain\Enums;

/**
 * Lifecycle of an acquisition run (ADR-0004 "partial failure").
 *
 * SUCCEEDED means zero document-level failures and no drift. A run with
 * per-document failures ends as COMPLETED_WITH_ERRORS; one that detected
 * drift ends as DEGRADED. Neither is ever reported as success.
 */
enum RunStatus: string
{
    case Pending = 'PENDING';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case CompletedWithErrors = 'COMPLETED_WITH_ERRORS';
    case Degraded = 'DEGRADED';
    case Failed = 'FAILED';

    /**
     * Whether the run has reached a terminal state.
     */
    public function isFinished(): bool
    {
        return match ($this) {
            self::Pending, self::Running => false,
            default => true,
        };
    }
}

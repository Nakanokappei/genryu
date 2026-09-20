<?php

namespace App\Acquisition\Application\Monitoring;

use RuntimeException;

/**
 * Monitoring did not start for a legitimate reason: no ACTIVE profile,
 * the source is disabled, or the circuit breaker is open. Not an error of
 * the platform, so the scheduler logs it and moves on.
 */
class MonitoringSkipped extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

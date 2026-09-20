<?php

namespace App\Acquisition\Domain\Enums;

/**
 * Whether a source participates in scheduling at all. Health is tracked
 * separately in HealthStatus; DISABLED here is a human decision.
 */
enum SourceStatus: string
{
    case Active = 'ACTIVE';
    case Disabled = 'DISABLED';
}

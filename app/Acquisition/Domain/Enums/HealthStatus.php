<?php

namespace App\Acquisition\Domain\Enums;

/**
 * Source health state machine (plan §13):
 *
 *   HEALTHY -> DEGRADED -> PARSER_DRIFT -> RECOVERY_PENDING -> HEALTHY
 *                       \-> DISABLED (human decision)
 *
 * Every transition is recorded as a source_events row with evidence.
 */
enum HealthStatus: string
{
    case Healthy = 'HEALTHY';
    case Degraded = 'DEGRADED';
    case ParserDrift = 'PARSER_DRIFT';
    case RecoveryPending = 'RECOVERY_PENDING';
    case Disabled = 'DISABLED';
}

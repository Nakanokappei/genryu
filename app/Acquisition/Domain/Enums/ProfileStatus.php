<?php

namespace App\Acquisition\Domain\Enums;

/**
 * Source Profile lifecycle (ADR-0005). Only the approve / rollback Artisan
 * commands move a profile into or out of ACTIVE; the Agent and the Storage
 * Tool can create PENDING_APPROVAL candidates and nothing else.
 */
enum ProfileStatus: string
{
    case PendingApproval = 'PENDING_APPROVAL';
    case Active = 'ACTIVE';
    case Superseded = 'SUPERSEDED';
    case Rejected = 'REJECTED';
}

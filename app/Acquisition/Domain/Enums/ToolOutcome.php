<?php

namespace App\Acquisition\Domain\Enums;

/**
 * Result of one Tool invocation as recorded in the audit trail.
 */
enum ToolOutcome: string
{
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
}

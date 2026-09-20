<?php

namespace App\Acquisition\Domain\Enums;

/**
 * Why an acquisition run exists (plan §5, §11). Discovery and Monitoring are
 * always separate runs; Monitoring never silently turns into Discovery.
 */
enum RunMode: string
{
    case Discovery = 'DISCOVERY';
    case Monitoring = 'MONITORING';
    case Reprocess = 'REPROCESS';
}

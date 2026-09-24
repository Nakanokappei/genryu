<?php

namespace App\Support;

use Throwable;

/** An exception's message fit for a status message column. */
class ErrorMessage
{
    /** The message as valid UTF-8, cut to $limit characters. */
    public static function of(Throwable $exception, int $limit = 1000): string
    {
        return mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, $limit);
    }
}

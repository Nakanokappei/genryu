<?php

namespace App\Support;

use Throwable;

/** An exception's message as a status message is kept: valid UTF-8, cut to fit. */
class ErrorMessage
{
    public static function of(Throwable $exception, int $limit = 1000): string
    {
        return mb_substr(mb_scrub($exception->getMessage(), 'UTF-8'), 0, $limit);
    }
}

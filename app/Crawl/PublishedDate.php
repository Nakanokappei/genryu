<?php

namespace App\Crawl;

use Carbon\CarbonImmutable;

/**
 * 公開日時 / 公開日: a printed date read as an instant when it names a time
 * and its zone, else as a day.
 */
class PublishedDate
{
    /** A printed date (RFC 2822, ISO 8601, 2026年9月17日) as Y-m-d, or ISO 8601 when it names a time and zone. */
    public static function parse(string $raw): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', '', $raw));

        // Nothing printed.
        if ($raw === '') {
            return null;
        }

        $raw = (string) preg_replace('/^(\d{4})年(\d{1,2})月(\d{1,2})日/u', '$1-$2-$3', $raw);

        // Unparseable: no date.
        try {
            $date = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        // A time counts only with its zone.
        $named = preg_match('/\d{1,2}:\d{2}/', $raw) === 1 && preg_match('/(Z|[+-]\d{2}:?\d{2}|GMT|UTC)$/i', $raw) === 1;

        return $named ? $date->toIso8601String() : $date->toDateString();
    }

    /** Whether a date read by self::parse() names a time (公開日時) or only a day (公開日). */
    public static function hasTime(?string $date): bool
    {
        return $date !== null && str_contains($date, 'T');
    }
}

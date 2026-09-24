<?php

namespace App\Crawl;

use Carbon\CarbonImmutable;

/**
 * 公開日時 / 公開日: the date a source prints for a document, kept as an
 * instant only when the source names a time and its zone, as a day
 * otherwise. Shared by the list readers and ReadDocument, which dates a
 * document the same way.
 */
class PublishedDate
{
    /**
     * A date as printed (RFC 2822, ISO 8601, or Japanese 2026年9月17日) to
     * Y-m-d, or to ISO 8601 when the source names a time and its zone.
     */
    public static function parse(string $raw): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', '', $raw));

        if ($raw === '') {
            return null;
        }

        $raw = (string) preg_replace('/^(\d{4})年(\d{1,2})月(\d{1,2})日/u', '$1-$2-$3', $raw);

        try {
            $date = CarbonImmutable::parse($raw);
        } catch (\Throwable) {
            return null;
        }

        // A time is kept only when the source also names the zone it is in
        // (a feed's pubDate does): a bare 10:00 could be any of them, and a
        // guessed instant would be shown as a wrong 公開日時.
        $named = preg_match('/\d{1,2}:\d{2}/', $raw) === 1 && preg_match('/(Z|[+-]\d{2}:?\d{2}|GMT|UTC)$/i', $raw) === 1;

        return $named ? $date->toIso8601String() : $date->toDateString();
    }

    /** Whether a date read by self::parse() names a time (公開日時) or only a day (公開日). */
    public static function hasTime(?string $date): bool
    {
        return $date !== null && str_contains($date, 'T');
    }
}

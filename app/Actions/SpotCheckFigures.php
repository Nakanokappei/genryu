<?php

namespace App\Actions;

use App\Models\SpotCheck;
use Illuminate\Support\Collection;

/**
 * 結果の数字 (UI: "Figures") of 抜き取り点検: what a person's verdicts on the
 * drawn documents say about the semantic filter, from the confirmed days
 * only. Every drawn document stands for as many documents of its day as
 * its stratum's weight (App\Actions\DrawSpotCheck), so rates are worked
 * out over the whole day, not over the draw, which over-samples what
 * passed and what fell just below the line. A verdict of 分からない is
 * left out of every rate. The likeness, the threshold and the outcome
 * are the ones kept when the document was drawn, before any verdict
 * could teach the filter.
 */
class SpotCheckFigures
{
    /** The thresholds the table looks at, lowest first. */
    public const THRESHOLDS = [-0.05, 0.0, 0.05, 0.10, 0.15, 0.20];

    /**
     * @return array{days: int, checks: int, judged: int, strata: list<array{stratum: string, drawn: int, like: int, unsure: int, unlike: int, agreed: ?float}>, missed_like: ?float, passed_unlike: ?float, thresholds: list<array{threshold: float, passed_per_day: float, like_kept: ?float}>}
     */
    public function __invoke(): array
    {
        $checks = SpotCheck::query()->whereNotNull('confirmed_at')->whereNotNull('verdict')->get();
        $days = $checks->pluck('drawn_on')->map(fn ($day): string => $day->toDateString())->unique()->count();
        $judged = $checks->whereIn('verdict', ['like', 'unlike']);

        return [
            'days' => $days,
            'checks' => $checks->count(),
            'judged' => $judged->count(),
            'strata' => array_map(fn (string $stratum): array => self::stratum($stratum, $checks->where('stratum', $stratum)), array_keys(SpotCheck::STRATA)),
            // Of the documents like this media, the share the filter left out.
            'missed_like' => self::share($judged->where('verdict', 'like'), fn (SpotCheck $check): bool => ! $check->passed),
            // Of the documents the filter let through, the share unlike this media.
            'passed_unlike' => self::share($judged->where('passed', true), fn (SpotCheck $check): bool => $check->verdict === 'unlike'),
            'thresholds' => array_map(fn (float $threshold): array => [
                'threshold' => $threshold,
                // Every document of the day at or above the line, estimated from the weights, per day.
                'passed_per_day' => $days === 0 ? 0.0 : (float) ($checks->filter(fn (SpotCheck $check): bool => $check->likeness >= $threshold)->sum('weight') / $days),
                'like_kept' => self::share($judged->where('verdict', 'like'), fn (SpotCheck $check): bool => $check->likeness >= $threshold),
            ], self::THRESHOLDS),
        ];
    }

    /**
     * One stratum as drawn: the verdicts, and how often the filter's outcome agreed with a person who could tell.
     *
     * @param  Collection<int, SpotCheck>  $checks
     * @return array{stratum: string, drawn: int, like: int, unsure: int, unlike: int, agreed: ?float}
     */
    private static function stratum(string $stratum, Collection $checks): array
    {
        $judged = $checks->whereIn('verdict', ['like', 'unlike']);

        return [
            'stratum' => $stratum,
            'drawn' => $checks->count(),
            'like' => $checks->where('verdict', 'like')->count(),
            'unsure' => $checks->where('verdict', 'unsure')->count(),
            'unlike' => $checks->where('verdict', 'unlike')->count(),
            'agreed' => $judged->isEmpty() ? null : (float) ($judged->filter(fn (SpotCheck $check): bool => ($check->verdict === 'like') === $check->passed)->count() / $judged->count()),
        ];
    }

    /**
     * The weighted share of some checks that meet a test, or null when there are none to share.
     *
     * @param  Collection<int, SpotCheck>  $checks
     * @param  callable(SpotCheck): bool  $test
     */
    private static function share(Collection $checks, callable $test): ?float
    {
        $total = $checks->sum('weight');

        return $total > 0 ? (float) ($checks->filter($test)->sum('weight') / $total) : null;
    }
}

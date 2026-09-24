<?php

namespace App\Actions;

use App\Models\SemanticFilterExample;
use App\Models\SpotCheck;
use Illuminate\Support\Collection;

/**
 * 結果の数字 of 抜き取り点検 (UI "Spot check"): rates of the semantic filter
 * from confirmed days, each check weighted by its stratum; cannot_tell
 * (分からない) is left out; likeness and outcome are as drawn.
 */
class SpotCheckFigures
{
    /** Thresholds in the table, lowest first. */
    public const THRESHOLDS = [-0.05, 0.0, 0.05, 0.10, 0.15, 0.20];

    /**
     * Computes the figures.
     *
     * @return array{days: int, checks: int, judged: int, strata: list<array{stratum: string, drawn: int, like: int, cannot_tell: int, unlike: int, agreed: ?float}>, missed_like: ?float, let_through_unlike: ?float, thresholds: list<array{threshold: float, let_through_per_day: float, like_kept: ?float}>}
     */
    public function __invoke(): array
    {
        $checks = SpotCheck::query()->whereNotNull('confirmed_at')->whereNotNull('verdict')->get();
        $days = $checks->pluck('drawn_on')->map(fn ($day): string => $day->toDateString())->unique()->count();
        $judged = $checks->whereIn('verdict', array_keys(SemanticFilterExample::SIDES));

        return [
            'days' => $days,
            'checks' => $checks->count(),
            'judged' => $judged->count(),
            'strata' => array_map(fn (string $stratum): array => self::stratum($stratum, $checks->where('stratum', $stratum)), array_keys(SpotCheck::STRATA)),
            // Of the documents like this media, the share the filter left out.
            'missed_like' => self::share($judged->where('verdict', 'like'), fn (SpotCheck $check): bool => ! $check->let_through),
            // Of the documents the filter let through, the share unlike this media.
            'let_through_unlike' => self::share($judged->where('let_through', true), fn (SpotCheck $check): bool => $check->verdict === 'unlike'),
            'thresholds' => array_map(fn (float $threshold): array => [
                'threshold' => $threshold,
                // Estimated documents per day at or above the threshold.
                'let_through_per_day' => $days === 0 ? 0.0 : (float) ($checks->filter(fn (SpotCheck $check): bool => $check->likeness >= $threshold)->sum('weight') / $days),
                'like_kept' => self::share($judged->where('verdict', 'like'), fn (SpotCheck $check): bool => $check->likeness >= $threshold),
            ], self::THRESHOLDS),
        ];
    }

    /**
     * One stratum's verdicts and the filter's agreement with them.
     *
     * @param  Collection<int, SpotCheck>  $checks
     * @return array{stratum: string, drawn: int, like: int, cannot_tell: int, unlike: int, agreed: ?float}
     */
    private static function stratum(string $stratum, Collection $checks): array
    {
        $judged = $checks->whereIn('verdict', array_keys(SemanticFilterExample::SIDES));

        return [
            'stratum' => $stratum,
            'drawn' => $checks->count(),
            'like' => $checks->where('verdict', 'like')->count(),
            'cannot_tell' => $checks->where('verdict', 'cannot_tell')->count(),
            'unlike' => $checks->where('verdict', 'unlike')->count(),
            'agreed' => $judged->isEmpty() ? null : (float) ($judged->filter(fn (SpotCheck $check): bool => ($check->verdict === 'like') === $check->let_through)->count() / $judged->count()),
        ];
    }

    /**
     * The weighted share of checks passing $test, or null when there are none.
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

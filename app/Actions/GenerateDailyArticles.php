<?php

namespace App\Actions;

use App\Jobs\GenerateArticle;
use App\Models\Article;
use App\Models\LanguageSetting;
use App\Models\Material;
use App\Models\ScheduleSetting;
use Carbon\CarbonImmutable;

/**
 * The day's articles, written on their own: up to 平日の公開本数 a day, from the
 * extracted materials of documents published within 対象期間 that some
 * language wants, the likeliest (らしさ) first.
 */
class GenerateDailyArticles
{
    /** Queue the day's articles; returns how many. */
    public function __invoke(CarbonImmutable $now): int
    {
        $setting = ScheduleSetting::current();
        $today = $now->setTimezone((string) config('app.display_timezone'))->startOfDay();
        $left = $setting->articles_per_weekday - Article::query()->originals()->where('created_at', '>=', $today->utc())->count();

        // Today's articles are already written.
        if ($left <= 0) {
            return 0;
        }

        $since = $now->subDays($setting->period_days);
        $materials = Material::query()->where('status', 'extracted')
            ->whereDoesntHave('articles', fn ($query) => $query->originals()->whereIn('status', ['generating', 'written']))
            ->with('document')->get()
            ->filter(fn (Material $material): bool => ($material->document->published_at ?? $material->document->created_at)->greaterThanOrEqualTo($since)
                && LanguageSetting::wantsArticle($material->document->language))
            ->sortByDesc(fn (Material $material): float => $material->document->likeness ?? -INF)
            ->take($left);

        $materials->each(fn (Material $material) => GenerateArticle::queueFor($material));

        return $materials->count();
    }
}

<?php

namespace App\Actions;

use App\Enums\Language;
use App\Models\Article;
use App\Models\ScheduleSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * スケジュール (UI "Schedule"): gives checked, unpublished originals within
 * 対象期間 the weekday slots ahead, best score first. A slot is a local date
 * and time applied in each language's zone. No model.
 */
class ScheduleArticles
{
    /** Days ahead searched for free slots. */
    private const HORIZON_DAYS = 60;

    /** Schedules what it can; returns how many articles got a slot. */
    public function __invoke(CarbonImmutable $now): int
    {
        $setting = ScheduleSetting::current();
        $slots = $setting->slots();

        self::followOriginals();

        // Waiting articles, best first, and the index of the next.
        $queue = self::candidates($now, $setting->period_days)->all();
        $next = 0;

        // Nothing to schedule or no slots.
        if ($queue === [] || $slots === []) {
            return 0;
        }

        // Slots taken, by the original's local date and time.
        $taken = Article::query()->originals()->whereNotNull('scheduled_at')->get()
            ->mapWithKeys(fn (Article $article): array => [$article->scheduledLocal()?->format('Y-m-d H:i') => true])->all();
        // From yesterday in UTC, so zones ahead of UTC are not skipped; past slots are passed over below.
        $start = $now->utc()->subDay()->startOfDay();
        // Each day of the horizon until the queue is empty.
        for ($offset = 0; $offset < self::HORIZON_DAYS && $next < count($queue); $offset++) {
            $date = $start->addDays($offset);

            // Weekdays only.
            if ($date->isWeekend()) {
                continue;
            }

            // Each free slot still ahead everywhere goes to the next article.
            foreach ($slots as $time) {
                $key = $date->format('Y-m-d')." {$time}";

                if ($next === count($queue) || isset($taken[$key]) || ! self::isAheadEverywhere($key, $now)) {
                    continue;
                }

                self::give($queue[$next++], $key);
                $taken[$key] = true;
            }
        }

        return $next;
    }

    /** Unschedules every unpublished article (part of スケジュールを組み直す, UI "Rebuild the schedule"). */
    public static function clear(): int
    {
        return Article::query()->whereNull('published_at')->whereNotNull('scheduled_at')->update(['scheduled_at' => null]);
    }

    /**
     * Checked, written, unpublished, unscheduled originals within the period, best score first.
     *
     * @return Collection<int, Article> in queue order, keyed from 0
     */
    private static function candidates(CarbonImmutable $now, int $days): Collection
    {
        $since = $now->subDays($days);

        return Article::query()->originals()->where('status', 'written')->whereNull('published_at')->whereNull('scheduled_at')
            ->whereRelation('qualityCheck', 'status', 'checked')
            ->with('qualityCheck', 'material.document')->get()
            ->filter(fn (Article $article): bool => ($article->material->document->published_at ?? $article->created_at)->greaterThanOrEqualTo($since))
            ->sortBy([fn (Article $a, Article $b): int => $b->qualityCheck->score <=> $a->qualityCheck->score, fn (Article $a, Article $b): int => $a->id <=> $b->id])
            ->values();
    }

    /** Whether a local slot is still ahead in every language's zone. */
    private static function isAheadEverywhere(string $slot, CarbonImmutable $now): bool
    {
        return array_all(Language::cases(), fn (Language $language): bool => CarbonImmutable::createFromFormat('Y-m-d H:i', $slot, $language->timezone())->greaterThan($now));
    }

    /**
     * Gives the original and its publishable translations the slot in their
     * own zones; the original always holds it, as the anchor.
     */
    private static function give(Article $original, string $slot): void
    {
        foreach ($original->translations()->get()->filter(fn (Article $translation): bool => $translation->isPublishable())->prepend($original) as $version) {
            $version->update(['scheduled_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $slot, $version->timezone())->utc()]);
        }
    }

    /** When a language version goes out: the original's local slot in that language's zone (UTC). */
    public static function timeFor(Article $original, string $language): ?CarbonImmutable
    {
        $local = $original->scheduledLocal();

        return $local === null ? null : CarbonImmutable::createFromFormat('Y-m-d H:i', $local->format('Y-m-d H:i'), Language::tryFrom($language)?->timezone() ?? 'UTC')->utc();
    }

    /** Gives unscheduled publishable translations their scheduled original's slot. */
    private static function followOriginals(): void
    {
        Article::query()->whereNotNull('translated_from_id')->whereNull('scheduled_at')->whereNull('published_at')
            ->whereHas('translatedFrom', fn ($query) => $query->whereNotNull('scheduled_at'))
            ->with('translatedFrom')->get()
            ->filter(fn (Article $translation): bool => $translation->isPublishable())
            ->each(fn (Article $translation) => $translation->update(['scheduled_at' => self::timeFor($translation->translatedFrom, (string) $translation->language)]));
    }
}

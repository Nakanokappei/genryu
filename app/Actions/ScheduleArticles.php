<?php

namespace App\Actions;

use App\Enums\Language;
use App\Models\Article;
use App\Models\ScheduleSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * スケジュール (UI: "Schedule"), a deterministic step of 編成: the checked,
 * unpublished articles whose primary source is still fresh (published
 * within the settings' days; the article's own date when the source gave
 * none) are given the slots of the coming weekdays, best score first,
 * earliest slot first. A slot is a date and a local time of day: every
 * language version of the article goes out at that date and time in its
 * own zone (Language::timezone), so a slot is used only when it is still
 * ahead in every one of them. A translation written after its original
 * was scheduled takes the original's slot. No model is called.
 */
class ScheduleArticles
{
    /** How many days ahead a free slot is looked for: more than any backlog of fresh articles needs. */
    private const HORIZON_DAYS = 60;

    /**
     * Schedule what can be scheduled; returns how many articles were given a slot.
     */
    public function __invoke(CarbonImmutable $now): int
    {
        $setting = ScheduleSetting::current();
        $slots = $setting->slots();

        self::followOriginals();

        // The queue of articles waiting, best first, and the next one to be given a slot.
        $queue = self::candidates($now, $setting->days)->all();
        $next = 0;

        if ($queue === [] || $slots === []) {
            return 0;
        }

        // The slots already given, by the original's local date and time.
        $taken = Article::query()->originals()->whereNotNull('scheduled_at')->get()
            ->mapWithKeys(fn (Article $article): array => [$article->scheduledLocal()?->format('Y-m-d H:i') => true])->all();
        // From yesterday in UTC, so the day that has already begun in the zones ahead of UTC is not skipped; a slot already past is passed over below.
        $start = $now->utc()->subDay()->startOfDay();
        for ($offset = 0; $offset < self::HORIZON_DAYS && $next < count($queue); $offset++) {
            $date = $start->addDays($offset);

            // Weekdays only; a date is the same weekday in every zone.
            if ($date->isWeekend()) {
                continue;
            }

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

    /**
     * Take every unpublished article off the schedule, so that it can be
     * made again (UI スケジュールを組み直す), after the settings changed.
     */
    public static function clear(): int
    {
        return Article::query()->whereNull('published_at')->whereNotNull('scheduled_at')->update(['scheduled_at' => null]);
    }

    /**
     * The articles waiting for a slot, best score first: checked, written,
     * unpublished and unscheduled originals whose source is still fresh.
     *
     * @return Collection<int, Article> in queue order, keyed from 0
     */
    private static function candidates(CarbonImmutable $now, int $days): Collection
    {
        $since = $now->subDays($days);

        return Article::query()->originals()->where('status', 'draft')->whereNull('published_at')->whereNull('scheduled_at')
            ->whereRelation('qualityCheck', 'status', 'checked')
            ->with('qualityCheck', 'material.document')->get()
            ->filter(fn (Article $article): bool => ($article->material->document->published_at ?? $article->created_at)->greaterThanOrEqualTo($since))
            ->sortBy([fn (Article $a, Article $b): int => $b->qualityCheck->score <=> $a->qualityCheck->score, fn (Article $a, Article $b): int => $a->id <=> $b->id])
            ->values();
    }

    /** Whether a slot, a local date and time, is still ahead in every zone an article is read in. */
    private static function isAheadEverywhere(string $slot, CarbonImmutable $now): bool
    {
        return array_all(Language::cases(), fn (Language $language): bool => CarbonImmutable::createFromFormat('Y-m-d H:i', $slot, $language->timezone())->greaterThan($now));
    }

    /**
     * Give an article and its translations a slot, each at that local date
     * and time in its own zone. The original always holds the slot, as the
     * anchor its translations take theirs from, even when its language
     * does not publish it (言語設定); a translation whose language no longer
     * publishes it gets none.
     */
    private static function give(Article $original, string $slot): void
    {
        foreach ($original->translations()->get()->filter(fn (Article $translation): bool => $translation->isPublishable())->prepend($original) as $version) {
            $version->update(['scheduled_at' => CarbonImmutable::createFromFormat('Y-m-d H:i', $slot, $version->timezone())->utc()]);
        }
    }

    /**
     * The time a language version of an article goes out at: its
     * original's local date and time of day, in its own zone.
     */
    public static function timeFor(Article $original, string $language): ?CarbonImmutable
    {
        $local = $original->scheduledLocal();

        return $local === null ? null : CarbonImmutable::createFromFormat('Y-m-d H:i', $local->format('Y-m-d H:i'), Language::tryFrom($language)?->timezone() ?? 'UTC')->utc();
    }

    /** A translation written after its original was scheduled takes the original's slot. */
    private static function followOriginals(): void
    {
        Article::query()->whereNotNull('translated_from_id')->whereNull('scheduled_at')->whereNull('published_at')
            ->whereHas('translatedFrom', fn ($query) => $query->whereNotNull('scheduled_at'))
            ->with('translatedFrom')->get()
            ->filter(fn (Article $translation): bool => $translation->isPublishable())
            ->each(fn (Article $translation) => $translation->update(['scheduled_at' => self::timeFor($translation->translatedFrom, (string) $translation->language)]));
    }
}

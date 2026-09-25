<?php

use App\Actions\ScheduleArticles;
use App\Models\Article;
use App\Models\Prompt;
use App\Models\ScheduleSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    // Wednesday 2026-09-23, 09:00 in Tokyo and 20:00 the evening before in New York.
    $this->travelTo(CarbonImmutable::parse('2026-09-23 00:00:00', 'UTC'));
    $this->actingAs(User::factory()->create());
});

/** A written original in Japanese, checked with the given score, whose source was published the given days ago. */
function checkedArticle(int $score, int $daysOld = 1): Article
{
    $article = Article::factory()->create(['language' => 'ja']);
    $article->material->document->update(['published_at' => now()->subDays($daysOld)]);
    $article->qualityChecks()->create(['prompt_id' => Prompt::current('quality', 'rubric')->id, 'model' => 'gpt-5.6-luna', 'status' => 'checked', 'score' => $score]);

    return $article;
}

function schedule(): int
{
    return app(ScheduleArticles::class)(CarbonImmutable::now());
}

/** An article's slot as its local date and time, the way the screen shows it. */
function slotOf(Article $article): ?string
{
    return $article->refresh()->scheduledLocal()?->format('D Y-m-d H:i');
}

// Best score first into the earliest slot still ahead everywhere: Wednesday's 07:00 and 09:00 have passed in Tokyo, so Wednesday takes three and Thursday the rest; below the pass mark (80) an article waits.
it('gives the fresh checked articles the coming weekday slots, best score first', function () {
    $scores = [70, 95, 80, 60, 85, 75, 90];
    $articles = array_map(fn (int $score): Article => checkedArticle($score), $scores);
    $stale = checkedArticle(99, daysOld: 10);
    $unchecked = Article::factory()->create(['language' => 'ja']);

    expect(schedule())->toBe(4);

    $slots = collect($articles)->mapWithKeys(fn (Article $article, int $index): array => [$scores[$index] => slotOf($article)])->sortKeysDesc()->all();
    expect($slots)->toBe([
        95 => 'Wed 2026-09-23 12:00',
        90 => 'Wed 2026-09-23 15:00',
        85 => 'Wed 2026-09-23 18:00',
        80 => 'Thu 2026-09-24 07:00',
        75 => null,
        70 => null,
        60 => null,
    ])
        // Past the period, or never checked, an article waits.
        ->and($stale->refresh()->scheduled_at)->toBeNull()
        ->and($unchecked->refresh()->scheduled_at)->toBeNull();

    // A second run finds nothing new, and does not move what it gave.
    expect(schedule())->toBe(0)->and(slotOf($articles[1]))->toBe('Wed 2026-09-23 12:00');
});

// Every language version goes out at the same local date and time in its own zone: English by New York, Chinese by Beijing and Taipei.
it('sets each language version at the same local time in its own zone', function () {
    $original = checkedArticle(90);
    $english = Article::factory()->for($original->material)->create(['translated_from_id' => $original->id, 'language' => 'en']);

    schedule();

    expect($original->refresh()->scheduled_at->toIso8601String())->toBe('2026-09-23T03:00:00+00:00')
        ->and($english->refresh()->scheduled_at->toIso8601String())->toBe('2026-09-23T16:00:00+00:00')
        ->and($english->scheduledLocal()->format('Y-m-d H:i e'))->toBe('2026-09-23 12:00 America/New_York');

    // A translation written after its original was scheduled takes the same slot.
    $taipei = Article::factory()->for($original->material)->create(['translated_from_id' => $original->id, 'language' => 'zh-Hant']);
    schedule();
    expect($taipei->refresh()->scheduledLocal()->format('Y-m-d H:i e'))->toBe('2026-09-23 12:00 Asia/Taipei');
});

// Weekdays only: a Friday with its slots full goes on to Monday.
it('skips the weekend', function () {
    ScheduleSetting::query()->create(['articles_per_weekday' => 1, 'period_days' => 7, 'publication_times' => ['12:00']]);
    $articles = array_map(fn (int $score): Article => checkedArticle($score), [95, 90, 85, 80]);

    schedule();

    expect(array_map(slotOf(...), $articles))->toBe(['Wed 2026-09-23 12:00', 'Thu 2026-09-24 12:00', 'Fri 2026-09-25 12:00', 'Mon 2026-09-28 12:00']);
});

// The settings live on the screen; making the schedule again takes the unpublished articles off it first.
it('keeps the settings on the schedule screen and makes the schedule again', function () {
    $best = checkedArticle(90);
    $next = checkedArticle(80);

    Livewire::test('pages::production.schedule.index')->call('schedule');
    expect(slotOf($best))->toBe('Wed 2026-09-23 12:00');

    Livewire::test('pages::production.schedule.index')
        ->set('articlesPerWeekday', 1)->set('publicationTimes', '18:00, 08:00')
        ->call('saveSettings')->assertHasNoErrors()
        ->call('reschedule');

    expect(ScheduleSetting::current()->slots())->toBe(['08:00'])
        ->and(slotOf($best))->toBe('Thu 2026-09-24 08:00')
        ->and(slotOf($next))->toBe('Fri 2026-09-25 08:00');

    Livewire::test('pages::production.schedule.index')->set('publicationTimes', '7時')->call('saveSettings')->assertHasErrors(['publicationTimes']);
    Livewire::test('pages::production.schedule.index')->set('articlesPerWeekday', 3)->set('publicationTimes', '08:00, 12:00')->call('saveSettings')->assertHasErrors(['articlesPerWeekday']);

    $this->get(route('production.schedule.index'))->assertSee('スケジュール')->assertSee('2026-09-24（木） 08:00')->assertSee('90');
});

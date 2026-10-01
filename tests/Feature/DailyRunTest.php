<?php

use App\Jobs\FetchSourceUpdates;
use App\Jobs\MakeImage;
use App\Jobs\RefineHeadline;
use App\Models\Article;
use App\Models\Material;
use App\Models\Prompt;
use App\Models\Source;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    // Wednesday 23 September 2026, 03:00 in Tokyo.
    $this->travelTo('2026-09-22 18:00:00');
});

/** An extracted material of a Japanese document published the given days ago, with its likeness. */
function extractedMaterial(float $likeness, int $daysOld = 1): Material
{
    $material = Material::factory()->create(['status' => 'extracted']);
    $material->document->update(['language' => 'ja', 'likeness' => $likeness, 'published_at' => now()->subDays($daysOld)]);

    return $material;
}

// 記事化: up to 平日の公開本数 (5) a day, from fresh materials, the likeliest first; a second run the same day adds none.
it('writes the day\'s articles from the likeliest fresh materials', function () {
    $materials = collect([0.30, 0.10, 0.25, 0.05, 0.20, 0.15, 0.40])->map(fn (float $likeness): Material => extractedMaterial($likeness));
    $stale = extractedMaterial(0.90, daysOld: 10);
    $written = extractedMaterial(0.80);
    Article::factory()->for($written)->create(['status' => 'written', 'created_at' => now()->subDays(2)]);

    $this->artisan('articles:generate')->expectsOutputToContain('5 articles queued')->assertSuccessful();

    // Queueing an article starts with its headline.
    Queue::assertPushed(RefineHeadline::class, 5);
    $queued = Article::query()->originals()->where('status', 'generating')->with('material.document')->get()->map(fn (Article $article): float => $article->material->document->likeness)->sort()->values()->all();
    expect($queued)->toBe([0.15, 0.20, 0.25, 0.30, 0.40])
        ->and($stale->articles()->exists())->toBeFalse();

    $this->artisan('articles:generate')->expectsOutputToContain('0 articles queued')->assertSuccessful();
    expect($materials)->toHaveCount(7);
});

// 更新リストを取得 for every configured source; one still configuring or failed waits.
it('queues the update lists of the configured sources', function () {
    $configured = Source::factory()->count(2)->create(['status' => 'configured']);
    Source::factory()->create(['status' => 'failed']);

    $this->artisan('updates:fetch')->expectsOutputToContain('2 update lists queued')->assertSuccessful();

    Queue::assertPushed(FetchSourceUpdates::class, 2);
    Queue::assertPushed(FetchSourceUpdates::class, fn (FetchSourceUpdates $job): bool => $configured->contains($job->source));
});

// スケジュール then 画像: an article at or above the pass mark gets a slot and its top image is queued; one below waits.
it('schedules the articles that pass and queues their images', function () {
    $passing = Article::factory()->create(['language' => 'ja', 'status' => 'written']);
    $failing = Article::factory()->create(['language' => 'ja', 'status' => 'written']);
    foreach ([[$passing, 85], [$failing, 76]] as [$article, $score]) {
        $article->material->document->update(['published_at' => now()->subDay()]);
        $article->qualityChecks()->create(['prompt_id' => Prompt::current('quality', 'rubric')->id, 'model' => 'gpt-5.6-luna', 'status' => 'checked', 'score' => $score]);
    }

    $this->artisan('articles:schedule')->expectsOutputToContain('1 articles scheduled, 1 images queued')->assertSuccessful();

    expect($passing->refresh()->scheduled_at)->not->toBeNull()
        ->and($failing->refresh()->scheduled_at)->toBeNull();
    Queue::assertPushed(MakeImage::class, fn (MakeImage $job): bool => $job->image->article->is($passing));
});

// Each language version is marked published once its time has come; one still ahead, or without a body, waits.
it('marks the articles whose time has come as published', function () {
    $due = Article::factory()->create(['language' => 'ja', 'status' => 'written', 'scheduled_at' => now()->subMinute()]);
    $translation = Article::factory()->create(['language' => 'en', 'translated_from_id' => $due->id, 'material_id' => $due->material_id, 'status' => 'written', 'scheduled_at' => now()->addHours(13)]);
    $unwritten = Article::factory()->create(['language' => 'ja', 'status' => 'generating', 'body' => null, 'scheduled_at' => now()->subHour()]);
    $done = Article::factory()->create(['language' => 'ja', 'status' => 'written', 'scheduled_at' => now()->subDay(), 'published_at' => now()->subDay()->addMinute()]);

    $this->artisan('articles:publish')->expectsOutputToContain('1 articles published')->assertSuccessful();

    expect($due->refresh()->published_at?->equalTo($due->scheduled_at))->toBeTrue()
        ->and($due->publicationStatus())->toBe('published')
        ->and($translation->refresh()->published_at)->toBeNull()
        ->and($unwritten->refresh()->published_at)->toBeNull()
        ->and($done->refresh()->published_at?->equalTo(now()->subDay()->addMinute()))->toBeTrue();

    // Its own time comes later in New York.
    $this->travel(14)->hours();
    $this->artisan('articles:publish')->expectsOutputToContain('1 articles published')->assertSuccessful();
    expect($translation->refresh()->published_at)->not->toBeNull();
});

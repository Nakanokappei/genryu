<?php

use App\Actions\GenerateDailyArticles;
use App\Actions\ScheduleArticles;
use App\Http\Controllers\MediaController;
use App\Jobs\FetchSourceUpdates;
use App\Jobs\MakeImage;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\EditorialPolicy;
use App\Models\ImageStyle;
use App\Models\LanguageSetting;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Deletes the top images drawn more than MediaController::WINDOW_DAYS days ago.
Artisan::command('media:prune-images', function () {
    $cutoff = now()->subDays(MediaController::WINDOW_DAYS);
    $pruned = 0;

    // Delete each old file and clear the paths pointing at it.
    ArticleImage::query()->whereNotNull('path')->where('created_at', '<', $cutoff)->each(function (ArticleImage $image) use (&$pruned): void {
        Storage::disk('local')->delete((string) $image->path);
        Article::query()->where('image_path', $image->path)->update(['image_path' => null]);
        $image->update(['path' => null]);
        $pruned++;
    });

    $this->info("{$pruned} top images older than ".MediaController::WINDOW_DAYS.' days deleted.');
})->purpose('Delete the top images drawn more than 30 days ago');

Schedule::command('media:prune-images')->daily();

// The day's run, on its own (Japan time): read the update lists (the rest follows from each document), write the day's articles, then schedule them and draw their images.
Artisan::command('updates:fetch', function () {
    $sources = Source::query()->where('status', 'configured')->get();
    $sources->each(fn (Source $source) => FetchSourceUpdates::dispatch($source));

    $this->info("{$sources->count()} update lists queued.");
})->purpose('Queue 更新リストを取得 for every configured source');

Artisan::command('articles:generate', function (GenerateDailyArticles $generate) {
    $this->info($generate(CarbonImmutable::now()).' articles queued.');
})->purpose('Queue the day\'s articles, up to 平日の公開本数');

Artisan::command('articles:schedule', function (ScheduleArticles $schedule) {
    $this->info($schedule(CarbonImmutable::now()).' articles scheduled, '.MakeImage::queueWaiting().' images queued.');
})->purpose('Give the articles at or above the pass mark their slots, then queue their images');

// Every language version with a body is published at its own scheduled time.
Artisan::command('articles:publish', function () {
    $published = Article::query()->whereNotNull('body')->whereNull('published_at')->where('scheduled_at', '<=', now())
        ->update(['published_at' => DB::raw('scheduled_at')]);

    $this->info("{$published} articles published.");
})->purpose('Mark the articles whose scheduled time has come as published');

Schedule::command('articles:publish')->everyMinute();
Schedule::command('updates:fetch')->dailyAt('01:00')->timezone((string) config('app.display_timezone'));
Schedule::command('articles:generate')->dailyAt('03:00')->timezone((string) config('app.display_timezone'));
Schedule::command('articles:schedule')->dailyAt('05:00')->timezone((string) config('app.display_timezone'));

// Copies the prompts (policy layers, image styles, language prompts) between the database and prompts/, one Markdown file each.
Artisan::command('prompts:export {--path=prompts}', function () {
    $root = base_path(is_string($this->option('path')) ? $this->option('path') : 'prompts');
    $files = [];

    // Policy layers.
    foreach (EditorialPolicy::query()->get() as $policy) {
        $files["policies/{$policy->layer}.md"] = (string) $policy->body;
    }

    // Image styles.
    foreach (ImageStyle::query()->get() as $style) {
        $files["image-styles/{$style->band}.md"] = (string) $style->style;
    }

    // Additional prompts per language.
    foreach (DB::table('language_settings')->whereNotNull('additional_prompt')->get() as $language) {
        $files["languages/{$language->language}.md"] = (string) $language->additional_prompt;
    }

    // Write the non-empty ones.
    foreach (array_filter($files, fn (string $text): bool => trim($text) !== '') as $name => $text) {
        File::ensureDirectoryExists(dirname("{$root}/{$name}"));
        File::put("{$root}/{$name}", rtrim($text)."\n");
    }

    $this->info(count(array_filter($files, fn (string $text): bool => trim($text) !== '')).' prompts written under '.$root);
})->purpose('Copy the prompts from the database to a folder kept out of Git');

Artisan::command('prompts:import {--path=prompts}', function () {
    $root = base_path(is_string($this->option('path')) ? $this->option('path') : 'prompts');
    $read = 0;

    // Policy layers.
    foreach (File::glob("{$root}/policies/*.md") as $file) {
        EditorialPolicy::query()->updateOrCreate(['layer' => basename($file, '.md')], ['body' => rtrim(File::get($file))]);
        $read++;
    }

    // Image styles, keeping a band's saved name and start.
    foreach (File::glob("{$root}/image-styles/*.md") as $file) {
        $band = basename($file, '.md');
        $defaults = ImageStyle::DEFAULTS[$band] ?? ['name' => $band, 'starts_at' => '00:00'];
        ImageStyle::query()->updateOrCreate(['band' => $band], ['name' => ImageStyle::query()->where('band', $band)->value('name') ?? $defaults['name'], 'starts_at' => ImageStyle::query()->where('band', $band)->value('starts_at') ?? $defaults['starts_at'], 'style' => rtrim(File::get($file))]);
        $read++;
    }

    // Additional prompts per language, keeping the coverage.
    foreach (File::glob("{$root}/languages/*.md") as $file) {
        $language = basename($file, '.md');
        LanguageSetting::query()->updateOrCreate(['language' => $language], ['additional_prompt' => rtrim(File::get($file)), 'coverage' => LanguageSetting::coverage($language)]);
        $read++;
    }

    $this->info("{$read} prompts read from {$root}");
})->purpose('Copy the prompts from a folder kept out of Git into the database');

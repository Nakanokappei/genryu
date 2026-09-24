<?php

use App\Http\Controllers\MediaController;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\EditorialPolicy;
use App\Models\ImageStyle;
use App\Models\LanguageSetting;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The top images are ours and take room: an image drawn more than MediaController::WINDOW_DAYS days ago goes, as the media site shows no further back.
Artisan::command('media:prune-images', function () {
    $cutoff = now()->subDays(MediaController::WINDOW_DAYS);
    $pruned = 0;

    ArticleImage::query()->whereNotNull('path')->where('created_at', '<', $cutoff)->each(function (ArticleImage $image) use (&$pruned): void {
        Storage::disk('local')->delete((string) $image->path);
        Article::query()->where('image_path', $image->path)->update(['image_path' => null]);
        $image->update(['path' => null]);
        $pruned++;
    });

    $this->info("{$pruned} top images older than ".MediaController::WINDOW_DAYS.' days deleted.');
})->purpose('Delete the top images drawn more than 30 days ago');

Schedule::command('media:prune-images')->daily();

/*
 * Prompts are assets (decided 2026-09-25): the ones edited on the screens
 * — the layers of the editorial policy, the styles of the top images,
 * the additional prompts per language — live in the database and never in
 * the repository, which is public. These two commands copy them to and
 * from a folder kept out of Git (prompts/, in .gitignore), to back them
 * up or to set up another environment. One Markdown file per prompt.
 */
Artisan::command('prompts:export {--path=prompts}', function () {
    $root = base_path(is_string($this->option('path')) ? $this->option('path') : 'prompts');
    $files = [];

    foreach (EditorialPolicy::query()->get() as $policy) {
        $files["policies/{$policy->layer}.md"] = (string) $policy->body;
    }

    foreach (ImageStyle::query()->get() as $style) {
        $files["image-styles/{$style->band}.md"] = (string) $style->style;
    }

    foreach (DB::table('language_settings')->whereNotNull('prompt')->get() as $language) {
        $files["languages/{$language->language}.md"] = (string) $language->prompt;
    }

    foreach (array_filter($files, fn (string $text): bool => trim($text) !== '') as $name => $text) {
        File::ensureDirectoryExists(dirname("{$root}/{$name}"));
        File::put("{$root}/{$name}", rtrim($text)."\n");
    }

    $this->info(count(array_filter($files, fn (string $text): bool => trim($text) !== '')).' prompts written under '.$root);
})->purpose('Copy the prompts from the database to a folder kept out of Git');

Artisan::command('prompts:import {--path=prompts}', function () {
    $root = base_path(is_string($this->option('path')) ? $this->option('path') : 'prompts');
    $read = 0;

    foreach (File::glob("{$root}/policies/*.md") as $file) {
        EditorialPolicy::query()->updateOrCreate(['layer' => basename($file, '.md')], ['body' => rtrim(File::get($file))]);
        $read++;
    }

    foreach (File::glob("{$root}/image-styles/*.md") as $file) {
        $band = basename($file, '.md');
        $defaults = ImageStyle::DEFAULTS[$band] ?? ['name' => $band, 'starts_at' => '00:00'];
        ImageStyle::query()->updateOrCreate(['band' => $band], ['name' => ImageStyle::query()->where('band', $band)->value('name') ?? $defaults['name'], 'starts_at' => ImageStyle::query()->where('band', $band)->value('starts_at') ?? $defaults['starts_at'], 'style' => rtrim(File::get($file))]);
        $read++;
    }

    foreach (File::glob("{$root}/languages/*.md") as $file) {
        $language = basename($file, '.md');
        DB::table('language_settings')->updateOrInsert(['language' => $language], ['prompt' => rtrim(File::get($file)), 'coverage' => DB::table('language_settings')->where('language', $language)->value('coverage') ?? (LanguageSetting::DEFAULTS[$language] ?? 'none'), 'updated_at' => now()]);
        $read++;
    }

    $this->info("{$read} prompts read from {$root}");
})->purpose('Copy the prompts from a folder kept out of Git into the database');

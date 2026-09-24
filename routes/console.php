<?php

use App\Http\Controllers\MediaController;
use App\Models\Article;
use App\Models\ArticleImage;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

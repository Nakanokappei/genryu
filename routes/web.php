<?php

use App\Http\Controllers\MediaController;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// The site opens on the media made with Genryu (its demo, プレビュー); the screens that make it are behind the sign-in.
Route::redirect('/', '/media')->name('home');

// メディアサイト (UI: "Media site"): Technology Watch as a reader sees it, the last 30 days, open to anyone.
Route::prefix('media')->name('media.')->group(function () {
    Route::get('/', [MediaController::class, 'index'])->name('index');
    Route::get('images/{article}', [MediaController::class, 'image'])->name('image');
    Route::get('{language}', [MediaController::class, 'index'])->whereIn('language', Article::LANGUAGES)->name('language');
    Route::get('{language}/articles/{article}', [MediaController::class, 'show'])->whereIn('language', Article::LANGUAGES)->name('article');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // 編集 (Editorial): the stages that make an article, from its sources to its writing (docs/HANDOVER.md), a list and a detail each.
    Route::prefix('editorial')->name('editorial.')->group(function () {
        Route::livewire('sources', 'pages::editorial.sources.index')->name('sources.index');
        Route::livewire('sources/{source}', 'pages::editorial.sources.show')->name('sources.show');
        // The site's icon, shown next to the source's name, from the local disk.
        Route::get('sources/{source}/favicon', fn (Source $source) => Storage::disk('local')->response((string) $source->favicon_path))->name('sources.favicon');
        Route::livewire('documents', 'pages::editorial.documents.index')->name('documents.index');
        Route::livewire('documents/{document}', 'pages::editorial.documents.show')->name('documents.show');
        // The original file (UI: "Original") of a document, as it was served, from the local disk.
        Route::get('documents/{document}/original', fn (Document $document) => Storage::disk('local')->download((string) $document->original_path, basename((string) $document->original_path)))->name('documents.original');
        // 意味フィルタ: the definitions and the examples of one side (like / unlike), each on a screen of its own.
        Route::livewire('semantic-filter/{side}', 'pages::editorial.semantic-filter.show')->whereIn('side', ['like', 'unlike'])->name('semantic-filter.show');
        Route::livewire('materials', 'pages::editorial.materials.index')->name('materials.index');
        Route::livewire('materials/{material}', 'pages::editorial.materials.show')->name('materials.show');
        Route::livewire('articles', 'pages::editorial.articles.index')->name('articles.index');
        Route::livewire('articles/{article}', 'pages::editorial.articles.show')->name('articles.show');
    });

    // 編成 (Production): the stages that take the written articles on to their publication.
    Route::prefix('production')->name('production.')->group(function () {
        Route::livewire('quality', 'pages::production.quality.index')->name('quality.index');
        Route::livewire('schedule', 'pages::production.schedule.index')->name('schedule.index');
        Route::livewire('images', 'pages::production.images.index')->name('images.index');
        // A top image as it was drawn, from the local disk.
        Route::get('images/{image}/file', fn (ArticleImage $image) => Storage::disk('local')->response((string) $image->path))->name('images.file');
        Route::livewire('articles', 'pages::production.articles.index')->name('articles.index');
    });

    // 監督 (Supervision): a person looking over what the stages did, after the fact; nothing in the pipeline waits for it.
    Route::prefix('supervision')->name('supervision.')->group(function () {
        Route::livewire('spot-checks', 'pages::supervision.spot-checks.index')->name('spot-checks.index');
    });

    // 編集方針 (Editorial policy): one screen, one body of text per layer.
});

require __DIR__.'/settings.php';

<?php

use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // 編集 (Editorial): the stages that make an article, from its sources to its writing (docs/HANDOVER.md), a list and a detail each.
    // 編成 (Production), which takes the articles on to their publication, gets its own group beside it.
    Route::prefix('editorial')->name('editorial.')->group(function () {
        Route::livewire('sources', 'pages::editorial.sources.index')->name('sources.index');
        Route::livewire('sources/{source}', 'pages::editorial.sources.show')->name('sources.show');
        // The site's icon, shown next to the source's name, from the local disk.
        Route::get('sources/{source}/favicon', fn (Source $source) => Storage::disk('local')->response((string) $source->favicon_path))->name('sources.favicon');
        Route::livewire('documents', 'pages::editorial.documents.index')->name('documents.index');
        Route::livewire('documents/{document}', 'pages::editorial.documents.show')->name('documents.show');
        // The original file (UI: "Original") of a document, as it was served, from the local disk.
        Route::get('documents/{document}/original', fn (Document $document) => Storage::disk('local')->download((string) $document->original_path, basename((string) $document->original_path)))->name('documents.original');
        Route::livewire('materials', 'pages::editorial.materials.index')->name('materials.index');
        Route::livewire('materials/{material}', 'pages::editorial.materials.show')->name('materials.show');
        Route::livewire('articles', 'pages::editorial.articles.index')->name('articles.index');
        Route::livewire('articles/{article}', 'pages::editorial.articles.show')->name('articles.show');
    });

    // 編集方針 (Editorial policy): one screen, one body of text per layer.
});

require __DIR__.'/settings.php';

<?php

use App\Models\Document;
use App\Models\Source;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // The stages of docs/HANDOVER.md as screens, a list and a detail each.
    Route::livewire('sources', 'pages::sources.index')->name('sources.index');
    Route::livewire('sources/{source}', 'pages::sources.show')->name('sources.show');
    // The site's icon, shown next to the source's name, from the local disk.
    Route::get('sources/{source}/favicon', fn (Source $source) => Storage::disk('local')->response((string) $source->favicon_path))->name('sources.favicon');
    Route::livewire('documents', 'pages::documents.index')->name('documents.index');
    Route::livewire('documents/{document}', 'pages::documents.show')->name('documents.show');
    // The original file (UI: "Original") of a document, as it was served, from the local disk.
    Route::get('documents/{document}/original', fn (Document $document) => Storage::disk('local')->download((string) $document->original_path, basename((string) $document->original_path)))->name('documents.original');
    Route::livewire('materials', 'pages::materials.index')->name('materials.index');
    Route::livewire('materials/{material}', 'pages::materials.show')->name('materials.show');
    Route::livewire('articles', 'pages::articles.index')->name('articles.index');
    Route::livewire('articles/{article}', 'pages::articles.show')->name('articles.show');

    // 編集方針 (Editorial policy): one screen, one body of text per layer.
});

require __DIR__.'/settings.php';

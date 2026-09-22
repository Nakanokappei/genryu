<?php

use App\Models\Source;
use App\Models\UpdateEntry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // The stages of docs/HANDOVER.md as screens, a list and a detail each; 文書 (Documents) lives on the 更新リスト (Updates) screens.
    Route::livewire('sources', 'pages::sources.index')->name('sources.index');
    Route::livewire('sources/{source}', 'pages::sources.show')->name('sources.show');
    // The site's icon, shown next to the source's name, from the local disk.
    Route::get('sources/{source}/favicon', fn (Source $source) => Storage::disk('local')->response((string) $source->favicon_path))->name('sources.favicon');
    Route::livewire('updates', 'pages::updates.index')->name('updates.index');
    Route::livewire('updates/{updateEntry}', 'pages::updates.show')->name('updates.show');
    // The original file (UI: "Original") of an update entry's document, as it was served, from the local disk.
    Route::get('updates/{updateEntry}/original', fn (UpdateEntry $updateEntry) => Storage::disk('local')->download((string) $updateEntry->original_path, basename((string) $updateEntry->original_path)))->name('updates.original');
    Route::livewire('materials', 'pages::materials.index')->name('materials.index');
    Route::livewire('materials/{material}', 'pages::materials.show')->name('materials.show');
    Route::livewire('articles', 'pages::articles.index')->name('articles.index');
    Route::livewire('articles/{article}', 'pages::articles.show')->name('articles.show');

    // 編集方針 (Editorial policy): one screen, one body of text per layer.
    Route::livewire('editorial-policy', 'pages::editorial-policy.index')->name('editorial-policy');
});

require __DIR__.'/settings.php';

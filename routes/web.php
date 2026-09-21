<?php

use App\Models\Document;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');

    // The five stages of docs/HANDOVER.md: a list and a detail screen each.
    Route::livewire('sources', 'pages::sources.index')->name('sources.index');
    Route::livewire('sources/{source}', 'pages::sources.show')->name('sources.show');
    Route::livewire('updates', 'pages::updates.index')->name('updates.index');
    Route::livewire('updates/{updateEntry}', 'pages::updates.show')->name('updates.show');
    Route::livewire('documents', 'pages::documents.index')->name('documents.index');
    Route::livewire('documents/{document}', 'pages::documents.show')->name('documents.show');
    // The original file (UI: "Original") as it was served, from the local disk.
    Route::get('documents/{document}/original', fn (Document $document) => Storage::disk('local')->download((string) $document->original_path, basename((string) $document->original_path)))->name('documents.original');
    Route::livewire('materials', 'pages::materials.index')->name('materials.index');
    Route::livewire('materials/{material}', 'pages::materials.show')->name('materials.show');
    Route::livewire('articles', 'pages::articles.index')->name('articles.index');
    Route::livewire('articles/{article}', 'pages::articles.show')->name('articles.show');

    // 編集方針 (Editorial policy): one screen, one body of text per layer.
    Route::livewire('editorial-policy', 'pages::editorial-policy.index')->name('editorial-policy');
});

require __DIR__.'/settings.php';

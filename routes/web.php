<?php

use Illuminate\Support\Facades\Route;

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
    Route::livewire('materials', 'pages::materials.index')->name('materials.index');
    Route::livewire('materials/{material}', 'pages::materials.show')->name('materials.show');
    Route::livewire('articles', 'pages::articles.index')->name('articles.index');
    Route::livewire('articles/{article}', 'pages::articles.show')->name('articles.show');
});

require __DIR__.'/settings.php';

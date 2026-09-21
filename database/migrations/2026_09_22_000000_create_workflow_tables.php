<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The five stages of docs/HANDOVER.md as tables, one per screen. Only the
 * columns needed to follow one item through the flow by hand; every stage
 * will grow its own columns when its feature is built.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 情報源 (Sources): the official sites we watch.
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('url', 2048);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 更新リスト (Updates): one entry per item found on a source's update list.
        Schema::create('update_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url', 2048);
            $table->date('published_at')->nullable();
            $table->timestamps();
        });

        // 文書 (Documents): the fetched HTML / PDF, kept as original and Markdown.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_entry_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url', 2048);
            $table->string('format', 16);
            $table->string('original_path', 2048)->nullable();
            $table->text('markdown')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->timestamps();
        });

        // 素材情報 (Materials): the structure extracted from a document, as JSON.
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->jsonb('data');
            $table->timestamps();
        });

        // 記事 (Articles): generated from materials; draft until published.
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('status', 16)->default('draft');
            $table->timestampTz('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('update_entries');
        Schema::dropIfExists('sources');
    }
};

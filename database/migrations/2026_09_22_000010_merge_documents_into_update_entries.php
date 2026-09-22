<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The 文書 (Documents) screen merges into 更新リスト (Updates): a document
 * was one row per update entry with the same title and URL, so the fetch
 * (original, Markdown, format, when, how it went) now lives on the update
 * entry itself and materials hang off the update entry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('update_entries', function (Blueprint $table) {
            $table->string('format', 16)->nullable()->after('excluded_by');
            $table->string('original_path', 2048)->nullable()->after('format');
            $table->text('markdown')->nullable()->after('original_path');
            $table->timestampTz('fetched_at')->nullable()->after('markdown');
            // null until a fetch is queued: fetching -> fetched | failed
            $table->string('status', 16)->nullable()->after('fetched_at');
            $table->text('status_message')->nullable()->after('status');
        });

        DB::statement('UPDATE update_entries SET format = d.format, original_path = d.original_path, markdown = d.markdown, fetched_at = d.fetched_at, status = d.status, status_message = d.status_message FROM documents d WHERE d.update_entry_id = update_entries.id');

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('update_entry_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE materials SET update_entry_id = d.update_entry_id FROM documents d WHERE d.id = materials.document_id');

        Schema::table('materials', function (Blueprint $table) {
            $table->dropUnique(['document_id']);
            $table->dropConstrainedForeignId('document_id');
            $table->unique('update_entry_id');
        });

        DB::statement('ALTER TABLE materials ALTER COLUMN update_entry_id SET NOT NULL');

        Schema::dropIfExists('documents');
    }

    public function down(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('update_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('url', 2048);
            $table->string('format', 16)->nullable();
            $table->string('original_path', 2048)->nullable();
            $table->text('markdown')->nullable();
            $table->timestampTz('fetched_at')->nullable();
            $table->string('status', 16)->default('fetching');
            $table->text('status_message')->nullable();
            $table->timestamps();
        });

        DB::statement('INSERT INTO documents (update_entry_id, title, url, format, original_path, markdown, fetched_at, status, status_message, created_at, updated_at) SELECT id, title, url, format, original_path, markdown, fetched_at, status, status_message, created_at, updated_at FROM update_entries WHERE status IS NOT NULL');

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('document_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE materials SET document_id = d.id FROM documents d WHERE d.update_entry_id = materials.update_entry_id');

        Schema::table('materials', function (Blueprint $table) {
            $table->dropUnique(['update_entry_id']);
            $table->dropConstrainedForeignId('update_entry_id');
            $table->unique('document_id');
        });

        DB::statement('ALTER TABLE materials ALTER COLUMN document_id SET NOT NULL');

        Schema::table('update_entries', function (Blueprint $table) {
            $table->dropColumn(['format', 'original_path', 'markdown', 'fetched_at', 'status', 'status_message']);
        });
    }
};

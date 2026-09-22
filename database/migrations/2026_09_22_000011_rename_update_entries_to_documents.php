<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The rows of a source's update list are the documents themselves (UI:
 * 文書): fetched from the source, kept as the original, read into
 * Markdown. The table and the keys that point at it say so. The
 * constraint and sequence names PostgreSQL keeps through a rename are
 * renamed by hand so nothing still says update_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('update_entries', 'documents');
        DB::statement('ALTER SEQUENCE update_entries_id_seq RENAME TO documents_id_seq');
        DB::statement('ALTER INDEX update_entries_pkey RENAME TO documents_pkey');
        DB::statement('ALTER TABLE documents RENAME CONSTRAINT update_entries_source_id_foreign TO documents_source_id_foreign');

        Schema::table('materials', function (Blueprint $table) {
            $table->renameColumn('update_entry_id', 'document_id');
        });
        DB::statement('ALTER TABLE materials RENAME CONSTRAINT materials_update_entry_id_foreign TO materials_document_id_foreign');
        DB::statement('ALTER INDEX materials_update_entry_id_unique RENAME TO materials_document_id_unique');
    }

    public function down(): void
    {
        DB::statement('ALTER INDEX materials_document_id_unique RENAME TO materials_update_entry_id_unique');
        DB::statement('ALTER TABLE materials RENAME CONSTRAINT materials_document_id_foreign TO materials_update_entry_id_foreign');
        Schema::table('materials', function (Blueprint $table) {
            $table->renameColumn('document_id', 'update_entry_id');
        });

        DB::statement('ALTER TABLE documents RENAME CONSTRAINT documents_source_id_foreign TO update_entries_source_id_foreign');
        DB::statement('ALTER INDEX documents_pkey RENAME TO update_entries_pkey');
        DB::statement('ALTER SEQUENCE documents_id_seq RENAME TO update_entries_id_seq');
        Schema::rename('documents', 'update_entries');
    }
};

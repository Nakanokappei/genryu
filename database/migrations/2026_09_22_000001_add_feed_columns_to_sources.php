<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fetching the update list (stage 2.1): remember the feed a source turned
 * out to have and when it was last read, and never list the same URL twice
 * for one source.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('feed_url', 2048)->nullable()->after('url');
            $table->timestampTz('fetched_at')->nullable()->after('notes');
        });

        Schema::table('update_entries', function (Blueprint $table) {
            $table->unique(['source_id', 'url']);
        });
    }

    public function down(): void
    {
        Schema::table('update_entries', function (Blueprint $table) {
            $table->dropUnique(['source_id', 'url']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['feed_url', 'fetched_at']);
        });
    }
};

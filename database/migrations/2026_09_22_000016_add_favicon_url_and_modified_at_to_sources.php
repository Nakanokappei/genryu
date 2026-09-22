<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A source's favicon is checked for changes every time its update list is
 * read: the URL it was fetched from and its Last-Modified are kept, so
 * the next check can ask the site with If-Modified-Since and take a new
 * icon only when there is one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->string('favicon_url', 2048)->nullable()->after('favicon_path');
            $table->timestamp('favicon_modified_at')->nullable()->after('favicon_url');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['favicon_url', 'favicon_modified_at']);
        });
    }
};

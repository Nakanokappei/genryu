<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Some sites (三菱電機) draw their update list in the browser from a JSON
 * file, so the HTML holds no entries. Such a source is read from that
 * JSON with per-source settings (UI: "JSON list settings"): the file's
 * URL, the path to the item array, the keys of title / link / date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->jsonb('json_config')->nullable()->after('list_config');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('json_config');
        });
    }
};

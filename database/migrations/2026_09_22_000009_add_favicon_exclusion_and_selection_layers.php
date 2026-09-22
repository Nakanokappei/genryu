<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sources show their favicon; the selection layer of the editorial policy
 * (UI: 取捨選択, set on the 更新リスト screen) becomes three layers, and
 * an update entry whose title has an excluded keyword remembers it (UI:
 * 対象外) instead of having its document fetched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // favicons/{source}.{ico|png|svg|…} on the local disk, fetched with the configuration.
            $table->string('favicon_path', 2048)->nullable()->after('url');
        });

        Schema::table('update_entries', function (Blueprint $table) {
            // The exclude keyword the title matched; null when the entry is fetched as usual.
            $table->string('excluded_by')->nullable()->after('published_at');
        });

        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->string('layer', 32)->change();
        });
    }

    public function down(): void
    {
        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->string('layer', 16)->change();
        });

        Schema::table('update_entries', function (Blueprint $table) {
            $table->dropColumn('excluded_by');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('favicon_path');
        });
    }
};

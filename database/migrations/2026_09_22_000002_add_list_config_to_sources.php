<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sources without a feed are read from their HTML list with a per-source
 * configuration (CSS selectors and a pagination rule), entered by hand now
 * and by an agent later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->jsonb('list_config')->nullable()->after('feed_url');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('list_config');
        });
    }
};

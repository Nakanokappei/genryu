<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A new source is configured in the background (feed discovery, then an
 * agent proposing HTML list settings); the screen shows where that stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // pending -> ready | failed (UI: 設定中 / 設定済み / 失敗)
            $table->string('status', 16)->default('pending')->after('list_config');
            $table->text('status_message')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['status', 'status_message']);
        });
    }
};

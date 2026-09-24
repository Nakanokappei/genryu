<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 確定 (UI: "Confirm"): a person closes a day's spot check once every
 * document of it is judged, so the day is done rather than left open on
 * its last page, and only confirmed days count in the figures. Kept on
 * every row of the day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('spot_checks', function (Blueprint $table) {
            $table->timestamp('confirmed_at')->nullable()->after('decided_at');
            $table->foreignId('confirmed_by')->nullable()->after('confirmed_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('spot_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropColumn('confirmed_at');
        });
    }
};

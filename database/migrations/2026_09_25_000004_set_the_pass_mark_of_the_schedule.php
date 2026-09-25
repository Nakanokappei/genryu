<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 合格点 (UI "Pass mark"): only articles whose quality check scores at least this are scheduled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('pass_mark')->default(80);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_settings', function (Blueprint $table): void {
            $table->dropColumn('pass_mark');
        });
    }
};

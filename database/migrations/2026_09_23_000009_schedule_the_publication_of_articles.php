<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スケジュール (UI: "Schedule"), the second screen of 編成 (Production):
 * each language version of an article gets the time it is to be
 * published (公開予定日時, `scheduled_at`, in UTC), and the settings the
 * schedule is made by live in one row: how many articles go out on a
 * weekday, how many days after its primary source an article is still
 * fresh enough, and the local times of day they go out at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->timestampTz('scheduled_at')->nullable()->after('published_at');
            $table->index('scheduled_at');
        });

        Schema::create('schedule_settings', function (Blueprint $table) {
            $table->id();
            // UI 平日の公開本数 / 対象期間（日） / 公開時刻
            $table->unsignedSmallInteger('articles_per_weekday');
            $table->unsignedSmallInteger('days');
            $table->jsonb('times');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_settings');

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropColumn('scheduled_at');
        });
    }
};

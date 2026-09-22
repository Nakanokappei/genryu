<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 公開日時 / 公開日: some sources date a document to the minute (a feed's
 * pubDate), others only to the day. The date column threw the time away,
 * so `published_at` becomes a timestamp and `published_has_time` says
 * whether there is a time in it — a day-only date is kept at midnight UTC
 * and shown without a timezone, a real instant is shown in the display
 * timezone. The documents listed so far are all day-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('published_has_time')->default(false)->after('published_at');
        });

        DB::statement('ALTER TABLE documents ALTER COLUMN published_at TYPE timestamptz USING published_at::timestamptz');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents ALTER COLUMN published_at TYPE date USING published_at::date');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('published_has_time');
        });
    }
};

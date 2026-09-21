<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2.4: articles are generated from materials in the background, per
 * the article generation layer of the editorial policy. An article row
 * appears as soon as the job is queued, so its title and body exist only
 * once the generation has run, and it carries where the generation stands
 * (UI: 生成中 / 下書き / 失敗, then 公開済み).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('title')->nullable()->change();
            $table->text('body')->nullable()->change();
            // generating -> draft | failed; draft -> published
            $table->string('status', 16)->default('generating')->change();
            $table->text('status_message')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('status_message');
            $table->string('status', 16)->default('draft')->change();
            $table->text('body')->nullable(false)->change();
            $table->string('title')->nullable(false)->change();
        });
    }
};

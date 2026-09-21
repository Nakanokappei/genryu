<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2.3: materials are extracted from documents in the background,
 * per the editorial policy. The policy lives in the app, one body per
 * layer (UI: 編集方針 — 取捨選択 / 構造化 / 記事生成); a material carries
 * where its extraction stands (UI: 抽出中 / 抽出済み / 失敗).
 */
return new class extends Migration
{
    public function up(): void
    {
        // 編集方針 (Editorial policy): one row per layer, edited on its own screen.
        Schema::create('editorial_policies', function (Blueprint $table) {
            $table->id();
            $table->string('layer', 16)->unique();
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::table('materials', function (Blueprint $table) {
            // The data exists only once the extraction has run.
            $table->jsonb('data')->nullable()->change();
            // extracting -> extracted | failed
            $table->string('status', 16)->default('extracting')->after('data');
            $table->text('status_message')->nullable()->after('status');
            $table->unique('document_id');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropUnique(['document_id']);
            $table->dropColumn(['status', 'status_message']);
        });

        Schema::dropIfExists('editorial_policies');
    }
};

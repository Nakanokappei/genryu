<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * トップ画像 (UI: "Top image"): an article that has its publication time
 * gets a top image before it goes out, kept on the local disk. Until it
 * has one the article is 画像作成中 on 編成's 記事 screen; the image itself
 * is made by a stage still to come.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};

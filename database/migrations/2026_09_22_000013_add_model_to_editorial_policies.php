<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A layer of the editorial policy can name the model that runs it (UI:
 * モデル on 編集方針 — コンテンツフィルタリング); null means the model in
 * OPENAI_MODEL, as for the other agents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->string('model', 64)->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->dropColumn('model');
        });
    }
};

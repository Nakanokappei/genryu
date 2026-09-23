<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 見出し is scored against a rubric and written again until it passes
 * (App\Jobs\RefineHeadline), so an article keeps what its headline
 * scored: the whole review as JSON, because the rubric will change and a
 * column per item would have to change with it, and the prompt version
 * and model the loop ran with, as every other stage keeps them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('headline_prompt_id')->nullable()->after('prompt_id')->constrained('prompts')->restrictOnDelete();
            $table->string('headline_model', 64)->nullable()->after('headline_prompt_id');
            $table->jsonb('headline_review')->nullable()->after('headline_model');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('headline_prompt_id');
            $table->dropColumn(['headline_model', 'headline_review']);
        });
    }
};

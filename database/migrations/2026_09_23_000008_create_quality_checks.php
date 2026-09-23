<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 品質チェック (UI: "Quality check"), the first screen of 編成
 * (Production): an article is scored against the quality layer of the
 * editorial policy, whose rubric is written in the policy itself. Every
 * run is a row, as a screening is, pinning the prompt version and the
 * model and keeping the usage; the screens show the latest one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quality_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained('prompts')->restrictOnDelete();
            $table->string('model', 64);
            // checking -> checked | failed (UI チェック中 / チェック済み / 失敗)
            $table->string('status', 16)->default('checking');
            $table->text('status_message')->nullable();
            // 品質 (UI: "Quality"): 0 to 100 by the rubric in the policy, and why.
            $table->unsignedSmallInteger('score')->nullable();
            $table->text('reason')->nullable();
            // Usage as reported by the API, the time the call took, and its cost in USD.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('estimated_total_cost', 12, 8)->nullable();
            $table->timestamps();
            $table->index(['article_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quality_checks');
    }
};

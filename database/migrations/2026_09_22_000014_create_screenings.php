<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * スクリーニング (UI: "Screening", the Editorial Screening Gate): after the
 * title filter and before the detailed analysis, an LLM reads a fetched
 * document's Markdown and decides 採用 / 不採用 / 要確認 (adopt / reject /
 * review). Every run is kept with the prompt version, the model, the
 * tokens (cached ones included) and the estimated cost, so the prompt
 * and the cache can be judged over time; the document points at its
 * latest run.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Every text the content filtering ran with, numbered: a run names the version it used.
        Schema::create('screening_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32);
            $table->unsignedInteger('version');
            $table->string('hash', 64);
            $table->text('text');
            $table->timestamp('activated_at');
            $table->timestamps();
            $table->unique(['name', 'version']);
        });

        Schema::create('screenings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('screening_prompt_id')->constrained()->restrictOnDelete();
            $table->string('model', 64);
            // screening -> screened | failed
            $table->string('status', 16)->default('screening');
            $table->text('status_message')->nullable();
            // adopt | reject | review, with the reason class, the fact in the document and the short reason the model gave.
            $table->string('decision', 16)->nullable();
            $table->string('primary_reason', 32)->nullable();
            $table->text('evidence')->nullable();
            $table->text('reason')->nullable();
            // Usage as reported by the API, and the time the call took.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            // In USD, from the prices in config/services.php; null when the model's prices are not known.
            $table->decimal('estimated_input_cost', 12, 8)->nullable();
            $table->decimal('estimated_output_cost', 12, 8)->nullable();
            $table->decimal('estimated_total_cost', 12, 8)->nullable();
            $table->timestamps();
            $table->index(['document_id', 'created_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            // The latest screening of the document, for the lists.
            $table->foreignId('screening_id')->nullable()->after('status_message')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('screening_id');
        });

        Schema::dropIfExists('screenings');
        Schema::dropIfExists('screening_prompts');
    }
};

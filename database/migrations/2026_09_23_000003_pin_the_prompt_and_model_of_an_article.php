<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記事生成 kept what the screening and the material already keep: the
 * prompt version and the model the article was written with, and what
 * the call used. Until now an article said only which model wrote it, in
 * its status message, so two articles written under different policies
 * could not be compared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->foreignId('prompt_id')->nullable()->after('material_id')->constrained()->restrictOnDelete();
            $table->string('model', 64)->nullable()->after('prompt_id');
            $table->unsignedInteger('input_tokens')->nullable()->after('status_message');
            $table->unsignedInteger('cached_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cached_tokens');
            $table->unsignedInteger('output_tokens')->nullable()->after('cache_write_tokens');
            $table->unsignedInteger('latency_ms')->nullable()->after('output_tokens');
            $table->decimal('estimated_total_cost', 12, 8)->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_id');
            $table->dropColumn(['model', 'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost']);
        });
    }
};

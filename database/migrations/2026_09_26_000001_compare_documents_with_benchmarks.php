<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ベンチマーク (UI "Benchmarks"): a source flagged as a benchmark is read
 * like any other, but of its documents only the embedding is kept; and
 * ベンチマーク類似度 (UI "Benchmark similarity") on each document of the
 * other sources, recorded beside the likeness without deciding anything yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table): void {
            $table->boolean('is_benchmark')->default(false)->after('notes');
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->double('benchmark_similarity')->nullable()->after('likeness_detail');
            $table->json('benchmark_detail')->nullable()->after('benchmark_similarity');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropColumn(['benchmark_similarity', 'benchmark_detail']);
        });

        Schema::table('sources', function (Blueprint $table): void {
            $table->dropColumn('is_benchmark');
        });
    }
};

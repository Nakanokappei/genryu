<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 抜き取り点検 (UI: "Spot check"): a few documents drawn each day from
 * those the semantic filter measured, and a person's verdict on each
 * (like / unlike this media, or unsure), so the filter's misses can be
 * estimated without anyone standing in the pipeline. The draw is
 * stratified (passed / just below the threshold / far below), each row
 * carrying the weight of its stratum, and keeps the likeness and the
 * threshold as they were when drawn, before the verdict can teach the
 * filter anything. The title and summary are put into Japanese for the
 * person who checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spot_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->date('drawn_on')->index();
            $table->string('stratum', 8);
            $table->double('weight');
            $table->double('likeness');
            $table->double('threshold');
            $table->boolean('passed');
            $table->text('title_ja')->nullable();
            $table->text('summary_ja')->nullable();
            $table->text('translation_error')->nullable();
            $table->string('verdict', 8)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spot_checks');
    }
};

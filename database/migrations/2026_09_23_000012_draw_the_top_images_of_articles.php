<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 画像 (UI: "Images"), the screen of 編成 that makes the top images: the
 * style of each time band of the day (時間帯ごとの絵柄: its name, the local
 * time it starts at and the style the image model is given), every
 * drawing of an article's top image with the scene the writer chose and
 * the prompt the image model was given, and on the article the local
 * time of day its current image was made for, so that an article moved to
 * another slot is drawn again in that slot's style.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_styles', function (Blueprint $table) {
            $table->id();
            $table->string('band', 32)->unique();
            $table->string('name');
            $table->string('starts_at', 5);
            $table->text('style');
            $table->timestamps();
        });

        Schema::create('article_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('article_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prompt_id')->constrained('prompts')->restrictOnDelete();
            // The writer of the scene, and the image model that drew it.
            $table->string('model', 64);
            $table->string('image_model', 64);
            // The local time of day the image was made for, and the band its style came from.
            $table->string('time', 5);
            $table->string('band', 32);
            // making -> made | failed (UI 作成中 / 作成済み / 失敗)
            $table->string('status', 16)->default('making');
            $table->text('status_message')->nullable();
            $table->text('scene')->nullable();
            // What the image model was given: the scene, the style and what is never drawn.
            $table->text('image_prompt')->nullable();
            $table->string('path')->nullable();
            // Usage of both calls as the API reported it, and the cost in USD.
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('image_input_tokens')->nullable();
            $table->unsignedInteger('image_output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->decimal('estimated_total_cost', 12, 8)->nullable();
            $table->timestamps();
            $table->index(['article_id', 'created_at']);
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->string('image_time', 5)->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('image_time');
        });

        Schema::dropIfExists('article_images');
        Schema::dropIfExists('image_styles');
    }
};

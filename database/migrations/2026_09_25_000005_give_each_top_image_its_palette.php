<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The palette the scene writer chose for a top image, so the next picture at the same hour can choose another.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('article_images', function (Blueprint $table): void {
            $table->string('palette')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('article_images', function (Blueprint $table): void {
            $table->dropColumn('palette');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 画像モデル (UI: "Image model"): the image layer of the editorial policy
 * runs two models, the one that writes the scene (`model`, as every
 * layer) and the one that draws it, kept beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->string('image_model', 64)->nullable()->after('model');
        });
    }

    public function down(): void
    {
        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->dropColumn('image_model');
        });
    }
};

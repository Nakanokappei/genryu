<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 記事 is written once, in the language of its primary source, and the
 * other languages are translations of that article rather than articles
 * written again from the material (decided 2026-09-23). A translation
 * keeps the nuance the reporter put in the original, and costs less than
 * writing the same piece several times over. So a material has several
 * articles — the original and its translations — each carrying the
 * language it is in, and a translation pointing at the article it came
 * from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('material_id');
            $table->foreignId('translated_from_id')->nullable()->after('language')->constrained('articles')->cascadeOnDelete();
            $table->index(['material_id', 'language']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['material_id', 'language']);
            $table->dropConstrainedForeignId('translated_from_id');
            $table->dropColumn('language');
        });
    }
};

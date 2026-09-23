<?php

use App\Actions\DetectLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 言語設定 (UI: "Language settings"): for each language we publish in, which
 * primary sources get an article in it — all of them, only those written
 * in it, or none — and the additional prompt every writer, headline and
 * translator is given when it writes in that language. A document gets
 * the language it is written in (言語), guessed from its text when it is
 * read and set right on the documents screen; the documents read so far
 * are guessed here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('language', 8)->nullable()->after('format');
        });

        Schema::create('language_settings', function (Blueprint $table) {
            $table->id();
            $table->string('language', 8)->unique();
            // all | own | none (UI すべての一次情報 / その言語の一次情報のみ / 作らない)
            $table->string('coverage', 8);
            $table->text('prompt')->nullable();
            $table->timestamps();
        });

        DB::table('documents')->whereNotNull('markdown')->orderBy('id')->each(function (object $document): void {
            DB::table('documents')->where('id', $document->id)->update(['language' => DetectLanguage::of($document->title."\n".$document->markdown)]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('language_settings');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};

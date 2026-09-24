<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 図版 (UI: "Figures") on an article: up to two figures of the primary
 * source the writer chose to quote, each with the section it stands in.
 * Only the figure's URL is kept, never the image: it is shown by the
 * reader's browser from the source's own server, as a quotation with
 * the source named. A translation shows its original's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->json('figures')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('figures');
        });
    }
};

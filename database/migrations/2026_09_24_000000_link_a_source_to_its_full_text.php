<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 全文へのリンク (UI: "Full text link"): for a source whose feed carries a
 * summary of every document (arXiv: the abstract), the CSS selectors of
 * the link to the full text on the document's page, one per line, tried
 * in order. A document is then screened on the summary from the feed and
 * its full text is fetched only once it is adopted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->text('full_text_link')->nullable()->after('document_config');
        });
    }

    public function down(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('full_text_link');
        });
    }
};

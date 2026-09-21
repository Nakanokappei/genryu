<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stage 2.2: documents are fetched in the background, one per update entry.
 * A source carries the settings that turn its pages into Markdown (CSS
 * selectors, proposed by an agent and verified before they are saved); a
 * document carries where its fetch stands (UI: 取得中 / 取得済み / 失敗).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sources', function (Blueprint $table) {
            // UI: "Document settings" (App\Actions\ReadDocument::DOCUMENT_CONFIG_KEYS)
            $table->jsonb('document_config')->nullable()->after('list_config');
        });

        Schema::table('documents', function (Blueprint $table) {
            // The format is known only once the page has been fetched.
            $table->string('format', 16)->nullable()->change();
            // fetching -> fetched | failed
            $table->string('status', 16)->default('fetching')->after('fetched_at');
            $table->text('status_message')->nullable()->after('status');
            $table->unique('update_entry_id');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['update_entry_id']);
            $table->dropColumn(['status', 'status_message']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn('document_config');
        });
    }
};

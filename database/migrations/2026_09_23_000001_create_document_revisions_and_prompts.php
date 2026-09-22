<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Markdown a document ever had is kept as a revision (UI: 版), so a
 * screening or a material can pin the text it was made from, and the
 * text is never replaced under it. The prompt versions, so far only the
 * screening's, become the prompts of every layer (table prompts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->text('markdown');
            $table->string('sha256', 64);
            $table->unsignedInteger('chars');
            $table->timestamp('created_at');
            $table->index(['document_id', 'id']);
        });

        // The Markdown each document has now is its first revision.
        foreach (DB::table('documents')->whereNotNull('markdown')->select('id', 'markdown', 'fetched_at', 'updated_at')->cursor() as $document) {
            DB::table('document_revisions')->insert([
                'document_id' => $document->id,
                'markdown' => $document->markdown,
                'sha256' => hash('sha256', $document->markdown),
                'chars' => mb_strlen($document->markdown),
                'created_at' => $document->fetched_at ?? $document->updated_at ?? now(),
            ]);
        }

        Schema::rename('screening_prompts', 'prompts');

        Schema::table('screenings', function (Blueprint $table) {
            $table->renameColumn('screening_prompt_id', 'prompt_id');
            $table->foreignId('document_revision_id')->nullable()->after('document_id')->constrained()->nullOnDelete();
        });

        // A screening made so far ran on the document's only revision.
        DB::statement('update screenings set document_revision_id = r.id from document_revisions r where r.document_id = screenings.document_id');

        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('document_revision_id')->nullable()->after('document_id')->constrained()->nullOnDelete();
            $table->foreignId('prompt_id')->nullable()->after('document_revision_id')->constrained()->restrictOnDelete();
            $table->string('model', 64)->nullable()->after('prompt_id');
            // The report of the checks the JSON went through (empty when it passed), and the usage of the calls that made it.
            $table->jsonb('validation')->nullable()->after('status_message');
            $table->unsignedInteger('input_tokens')->nullable()->after('validation');
            $table->unsignedInteger('cached_tokens')->nullable()->after('input_tokens');
            $table->unsignedInteger('cache_write_tokens')->nullable()->after('cached_tokens');
            $table->unsignedInteger('output_tokens')->nullable()->after('cache_write_tokens');
            $table->unsignedInteger('latency_ms')->nullable()->after('output_tokens');
            $table->decimal('estimated_total_cost', 12, 8)->nullable()->after('latency_ms');
        });
    }

    public function down(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_revision_id');
            $table->dropConstrainedForeignId('prompt_id');
            $table->dropColumn(['model', 'validation', 'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost']);
        });

        Schema::table('screenings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('document_revision_id');
            $table->renameColumn('prompt_id', 'screening_prompt_id');
        });

        Schema::rename('prompts', 'screening_prompts');
        Schema::dropIfExists('document_revisions');
    }
};

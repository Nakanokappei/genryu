<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 意味フィルタ (UI: "Semantic filter"), between the title filter and the
 * screening, for every source: a document's title and text are embedded
 * and set against definitions of what is and is not like this media, and
 * against examples a person chose. らしさ (UI: "Likeness") is how much
 * nearer the nearest "like" is than the nearest "unlike"; below the
 * threshold (閾値) the document goes no further. The embedding is kept
 * apart from the document (3,072 numbers, which a list of documents must
 * not load), so a changed definition or threshold is measured again
 * without calling the model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->decimal('threshold', 6, 3)->nullable()->after('image_model');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->double('likeness')->nullable()->after('excluded_by');
            $table->json('likeness_detail')->nullable()->after('likeness');
        });

        Schema::create('document_embeddings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('model', 64);
            $table->json('vector');
            $table->unsignedInteger('tokens')->nullable();
            $table->timestamps();
        });

        Schema::create('semantic_filter_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('side', 8);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semantic_filter_examples');
        Schema::dropIfExists('document_embeddings');

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['likeness', 'likeness_detail']);
        });

        Schema::table('editorial_policies', function (Blueprint $table) {
            $table->dropColumn('threshold');
        });
    }
};

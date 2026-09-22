<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 人の判定 (UI: "Human decision"): a person's verdict on a document, adopt or
 * reject with a line of reason, recorded on the document screen. It
 * outranks the screening's decision at the gate, and is kept to become
 * an example for the screening later (docs/TODO.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('human_decision', 16)->nullable()->after('screening_id');
            $table->text('human_reason')->nullable()->after('human_decision');
            $table->timestamp('human_decided_at')->nullable()->after('human_reason');
            $table->foreignId('human_decided_by')->nullable()->after('human_decided_at')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('human_decided_by');
            $table->dropColumn(['human_decision', 'human_reason', 'human_decided_at']);
        });
    }
};

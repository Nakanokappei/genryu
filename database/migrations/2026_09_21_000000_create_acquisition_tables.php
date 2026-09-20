<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 0 persistence for the Primary Source Acquisition Platform.
 *
 * Table names follow the implementation plan (§8) verbatim. The three
 * artifact layers (RAW / NORMALIZED / DERIVED) keep only metadata here;
 * the bytes live in the content-addressed BlobStore (ADR-0002).
 *
 * PostgreSQL only: the partial unique index that guarantees "one ACTIVE
 * profile per source" (ADR-0005) and the partial index on unresolved
 * events have no portable Blueprint equivalent.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The organisations we monitor. Circuit-breaker state (ADR-0004)
        // lives here because the scheduler reads it per source.
        Schema::create('sources', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('base_url');
            $table->string('status', 32)->default('ACTIVE');
            $table->string('health_status', 32)->default('HEALTHY');
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->timestampTz('next_run_not_before')->nullable();
            $table->timestampsTz();

            $table->index('health_status');
        });

        // Versioned monitoring specifications. The run that produced a
        // profile is linked after acquisition_runs exists (see below).
        Schema::create('source_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->unsignedInteger('version');
            $table->unsignedInteger('schema_version');
            $table->string('status', 32);
            $table->jsonb('profile_json');
            $table->unsignedBigInteger('created_by_run_id')->nullable();
            $table->text('change_reason')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->string('approved_by')->nullable();
            $table->timestampTz('superseded_at')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->timestampsTz();

            $table->unique(['source_id', 'version']);
        });

        // At most one ACTIVE version per source, enforced by the database
        // rather than by application code (ADR-0005).
        DB::statement(
            "CREATE UNIQUE INDEX source_profiles_one_active_per_source
             ON source_profiles (source_id) WHERE status = 'ACTIVE'"
        );

        // One Discovery / Monitoring / Reprocess execution.
        Schema::create('acquisition_runs', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 32);
            $table->foreignId('source_id')->constrained();
            $table->foreignId('profile_id')->nullable()->constrained('source_profiles');
            $table->string('status', 32)->default('PENDING');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->jsonb('budget')->nullable();
            $table->jsonb('counters')->default('{}');
            $table->jsonb('agent_metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['source_id', 'status']);
            $table->index(['status', 'started_at']);
        });

        Schema::table('source_profiles', function (Blueprint $table) {
            $table->foreign('created_by_run_id')->references('id')->on('acquisition_runs');
        });

        // Audit trail of every Tool call made on behalf of a run.
        Schema::create('tool_invocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('acquisition_runs');
            $table->string('tool', 64);
            $table->char('request_digest', 64);
            $table->string('outcome', 32);
            $table->string('error_code', 64)->nullable();
            $table->unsignedInteger('duration_ms');
            $table->string('correlation_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('run_id');
        });

        // Immutable originals. No updated_at on purpose: rows are never
        // updated (AT-03), and the model enforces it as well.
        Schema::create('raw_artifacts', function (Blueprint $table) {
            $table->id();
            $table->string('blob_uri')->unique();
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('bytes');
            $table->string('media_type', 128);
            $table->jsonb('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // URLs seen during discovery or monitoring, deduplicated per source.
        Schema::create('discovered_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->foreignId('first_seen_run_id')->constrained('acquisition_runs');
            $table->foreignId('last_seen_run_id')->constrained('acquisition_runs');
            $table->text('url');
            $table->text('normalized_url');
            $table->string('relation', 32);
            $table->string('media_type', 128)->nullable();
            $table->unsignedSmallInteger('depth')->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');

            $table->unique(['source_id', 'normalized_url']);
        });

        // The fact of one HTTP fetch, successful or not.
        Schema::create('fetch_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('acquisition_runs');
            $table->foreignId('source_id')->constrained();
            $table->text('url');
            $table->text('final_url')->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->jsonb('headers')->nullable();
            $table->timestampTz('retrieved_at');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->foreignId('raw_artifact_id')->nullable()->constrained();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();

            $table->index(['source_id', 'retrieved_at']);
            $table->index('run_id');
            $table->index('raw_artifact_id');
        });

        // A logical document within a source, keyed by its stable identity
        // (ADR-0003), never by URL alone.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->string('stable_key', 1024);
            $table->string('identity_rule', 32);
            $table->text('canonical_url')->nullable();
            $table->string('document_type', 32)->nullable();
            $table->timestampTz('first_seen_at');
            $table->timestampTz('last_seen_at');
            $table->timestampsTz();

            $table->unique(['source_id', 'stable_key']);
        });

        // Other URLs that resolved to the same document, with the reason.
        Schema::create('document_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained();
            $table->text('url');
            $table->text('normalized_url');
            $table->string('reason', 32);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_id', 'normalized_url']);
        });

        // Content history. The (document_id, content_hash) constraint is
        // what makes append_document_revision idempotent (AT-05).
        Schema::create('document_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained();
            $table->unsignedInteger('revision_no');
            $table->foreignId('raw_artifact_id')->constrained();
            $table->char('content_hash', 64);
            $table->timestampTz('detected_at');
            $table->foreignId('detected_in_run_id')->nullable()->constrained('acquisition_runs');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['document_id', 'revision_no']);
            $table->unique(['document_id', 'content_hash']);
            $table->index('raw_artifact_id');
        });

        // Parser output. Several parser versions may coexist per revision.
        Schema::create('normalized_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('revision_id')->constrained('document_revisions');
            $table->string('parser_id', 64);
            $table->string('normalizer_version', 64);
            $table->string('blob_uri');
            $table->char('sha256', 64);
            $table->jsonb('quality')->nullable();
            $table->jsonb('warnings')->nullable();
            $table->foreignId('produced_in_run_id')->nullable()->constrained('acquisition_runs');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['revision_id', 'parser_id', 'normalizer_version']);
            $table->index('sha256');
        });

        // Boundary for future derivations. Phase 0 never writes here.
        Schema::create('derived_artifacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('normalized_artifact_id')->constrained();
            $table->string('derivation_type', 64);
            $table->string('derivation_version', 64);
            $table->string('blob_uri');
            $table->char('sha256', 64);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['normalized_artifact_id', 'derivation_type', 'derivation_version']);
        });

        // Per-source metrics with the baseline they were compared against.
        Schema::create('health_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->foreignId('run_id')->nullable()->constrained('acquisition_runs');
            $table->string('metric', 64);
            $table->decimal('value', 18, 6);
            $table->decimal('baseline', 18, 6)->nullable();
            $table->string('status', 32)->nullable();
            $table->timestampTz('observed_at');

            $table->index(['source_id', 'metric', 'observed_at']);
        });

        // Drift, outages, profile transitions, identity conflicts.
        Schema::create('source_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_id')->constrained();
            $table->foreignId('run_id')->nullable()->constrained('acquisition_runs');
            $table->string('event_type', 64);
            $table->string('severity', 16);
            $table->jsonb('evidence')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['source_id', 'event_type']);
        });

        // Operators mostly ask "what is still open for this source?".
        DB::statement(
            'CREATE INDEX source_events_unresolved
             ON source_events (source_id) WHERE resolved_at IS NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_events');
        Schema::dropIfExists('health_observations');
        Schema::dropIfExists('derived_artifacts');
        Schema::dropIfExists('normalized_artifacts');
        Schema::dropIfExists('document_revisions');
        Schema::dropIfExists('document_aliases');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('fetch_observations');
        Schema::dropIfExists('discovered_resources');
        Schema::dropIfExists('raw_artifacts');
        Schema::dropIfExists('tool_invocations');

        // source_profiles -> acquisition_runs -> source_profiles is circular,
        // so the back-reference goes first.
        Schema::table('source_profiles', function (Blueprint $table) {
            $table->dropForeign(['created_by_run_id']);
        });
        Schema::dropIfExists('acquisition_runs');
        Schema::dropIfExists('source_profiles');
        Schema::dropIfExists('sources');
    }
};

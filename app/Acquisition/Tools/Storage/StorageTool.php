<?php

namespace App\Acquisition\Tools\Storage;

use App\Acquisition\Domain\Enums\BlobLayer;
use App\Acquisition\Domain\Enums\ProfileStatus;
use App\Acquisition\Domain\Identity\StableKey;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\DiscoveredResource;
use App\Acquisition\Domain\Models\Document;
use App\Acquisition\Domain\Models\DocumentRevision;
use App\Acquisition\Domain\Models\FetchObservation;
use App\Acquisition\Domain\Models\HealthObservation;
use App\Acquisition\Domain\Models\NormalizedArtifact;
use App\Acquisition\Domain\Models\RawArtifact;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Domain\Profile\SourceProfileValidator;
use App\Acquisition\Infrastructure\BlobStorage\BlobStore;
use App\Acquisition\Tools\Http\FetchResult;
use App\Acquisition\Tools\Normalize\NormalizedDocument;
use App\Acquisition\Tools\ToolError;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Storage Tool (plan §7.7): the only way anything reaches PostgreSQL or the
 * BlobStore. Owns transactions, idempotency, hashing, revision numbering and
 * provenance. Invariants it enforces:
 *
 *  - RAW is append-only; identical bytes are stored once.
 *  - Same identity + same content hash never creates a revision.
 *  - Same identity + different hash appends revision_no + 1.
 *  - Profile candidates are validated and always land as PENDING_APPROVAL.
 */
final class StorageTool
{
    public function __construct(
        private BlobStore $blobs,
        private SourceProfileValidator $profiles,
    ) {}

    /**
     * store_raw_artifact: persist fetched bytes as an immutable original.
     * Blob first, row second (ADR-0002); a repeated call with the same bytes
     * returns the existing row.
     */
    public function storeRawArtifact(FetchResult $fetch): RawArtifact
    {
        $ref = $this->blobs->put(BlobLayer::Raw, $fetch->body, $fetch->declaredMediaType ?? $fetch->detectedMediaType ?? 'application/octet-stream');

        return RawArtifact::query()->firstOrCreate(['sha256' => $ref->sha256], [
            'blob_uri' => $ref->uri,
            'bytes' => $ref->bytes,
            'media_type' => $ref->mediaType,
            'metadata' => [
                'requested_url' => $fetch->requestedUrl,
                'final_url' => $fetch->finalUrl,
                'redirect_chain' => $fetch->redirectChain,
                'status' => $fetch->status,
                'headers' => $fetch->headers,
                'declared_media_type' => $fetch->declaredMediaType,
                'detected_media_type' => $fetch->detectedMediaType,
                'retrieved_at' => $fetch->retrievedAt->toIso8601ZuluString(),
            ],
        ]);
    }

    /**
     * Record the fact of a fetch, successful or failed. Always inserts: the
     * observation is the operational trace that idempotent monitoring still
     * leaves behind (AT-05).
     */
    public function recordFetchObservation(AcquisitionRun $run, Source $source, string $url, ?FetchResult $fetch, ?RawArtifact $raw = null, ?ToolError $error = null): FetchObservation
    {
        return FetchObservation::query()->create([
            'run_id' => $run->id,
            'source_id' => $source->id,
            'url' => $url,
            'final_url' => $fetch?->finalUrl,
            'status' => $fetch?->status,
            'headers' => $fetch?->headers,
            'retrieved_at' => $fetch->retrievedAt ?? CarbonImmutable::now('UTC'),
            'duration_ms' => $fetch?->durationMs,
            'attempts' => $fetch->attempts ?? 1,
            'raw_artifact_id' => $raw?->id,
            'error_code' => $error?->errorCode->value,
            'error_message' => $error?->getMessage(),
        ]);
    }

    /**
     * upsert_document_identity: find or create the document for a stable
     * key, refresh last_seen_at, and remember any other URL that led here.
     */
    public function upsertDocumentIdentity(Source $source, StableKey $identity, ?string $canonicalUrl, ?string $documentType, string $seenViaUrl, CarbonImmutable $seenAt): Document
    {
        return DB::transaction(function () use ($source, $identity, $canonicalUrl, $documentType, $seenViaUrl, $seenAt): Document {
            $document = Document::query()->firstOrCreate(
                ['source_id' => $source->id, 'stable_key' => $identity->key],
                [
                    'identity_rule' => $identity->rule,
                    'canonical_url' => $canonicalUrl,
                    'document_type' => $documentType,
                    'first_seen_at' => $seenAt,
                    'last_seen_at' => $seenAt,
                ],
            );

            if ($document->last_seen_at->lessThan($seenAt)) {
                $document->update(['last_seen_at' => $seenAt]);
            }

            // Any URL other than the identity URL is an alias worth keeping.
            $normalized = UrlNormalizer::normalize($seenViaUrl);

            if ($identity->key !== 'url:'.$normalized) {
                $document->aliases()->firstOrCreate(
                    ['normalized_url' => $normalized],
                    ['url' => $seenViaUrl, 'reason' => $identity->rule],
                );
            }

            return $document;
        });
    }

    /**
     * append_document_revision: idempotent on (document, content hash).
     */
    public function appendDocumentRevision(Document $document, RawArtifact $raw, ?AcquisitionRun $run, CarbonImmutable $detectedAt): RevisionAppendResult
    {
        return DB::transaction(function () use ($document, $raw, $run, $detectedAt): RevisionAppendResult {
            // Serialise revision numbering per document.
            Document::query()->whereKey($document->id)->lockForUpdate()->first();

            $existing = $document->revisions()->where('content_hash', $raw->sha256)->first();

            if ($existing !== null) {
                return new RevisionAppendResult($existing, false);
            }

            $next = ((int) $document->revisions()->max('revision_no')) + 1;

            try {
                $revision = $document->revisions()->create([
                    'revision_no' => $next,
                    'raw_artifact_id' => $raw->id,
                    'content_hash' => $raw->sha256,
                    'detected_at' => $detectedAt,
                    'detected_in_run_id' => $run?->id,
                ]);
            } catch (QueryException $exception) {
                // Lost a race against an identical append: return theirs.
                $existing = $document->revisions()->where('content_hash', $raw->sha256)->first();

                if ($existing === null) {
                    throw $exception;
                }

                return new RevisionAppendResult($existing, false);
            }

            return new RevisionAppendResult($revision, true);
        });
    }

    /**
     * store_normalized_artifact: one row per (revision, parser, normalizer
     * version). Re-normalizing with the same versions is a no-op.
     */
    public function storeNormalizedArtifact(DocumentRevision $revision, NormalizedDocument $document, ?AcquisitionRun $run): NormalizedArtifact
    {
        $ref = $this->blobs->put(BlobLayer::Normalized, $document->toMarkdown(), 'text/markdown');

        return NormalizedArtifact::query()->firstOrCreate(
            ['revision_id' => $revision->id, 'parser_id' => $document->parserId, 'normalizer_version' => $document->normalizerVersion],
            [
                'blob_uri' => $ref->uri,
                'sha256' => $ref->sha256,
                'quality' => $document->quality,
                'warnings' => $document->warnings,
                'produced_in_run_id' => $run?->id,
            ],
        );
    }

    /**
     * store_discovery_result: remember every URL a run encountered, once per
     * source, with first/last seen bookkeeping.
     *
     * @param  list<array{url: string, relation: string, media_type?: string|null, depth?: int|null}>  $resources
     * @return int number of resources seen for the first time
     */
    public function storeDiscoveryResult(AcquisitionRun $run, Source $source, array $resources, CarbonImmutable $seenAt): int
    {
        $new = 0;

        foreach ($resources as $resource) {
            $resourceRow = DiscoveredResource::query()->firstOrNew([
                'source_id' => $source->id,
                'normalized_url' => UrlNormalizer::normalize($resource['url']),
            ]);

            if (! $resourceRow->exists) {
                $new++;
                $resourceRow->fill([
                    'first_seen_run_id' => $run->id,
                    'url' => $resource['url'],
                    'relation' => $resource['relation'],
                    'media_type' => $resource['media_type'] ?? null,
                    'depth' => $resource['depth'] ?? null,
                    'first_seen_at' => $seenAt,
                ]);
            }

            $resourceRow->fill(['last_seen_run_id' => $run->id, 'last_seen_at' => $seenAt])->save();
        }

        return $new;
    }

    /**
     * store_source_profile_candidate: validate, assign the next version, and
     * save as PENDING_APPROVAL. Only the approval command can make it ACTIVE.
     *
     * @param  array<string, mixed>  $profile
     */
    public function storeSourceProfileCandidate(Source $source, array $profile, ?AcquisitionRun $run, ?string $changeReason = null): SourceProfile
    {
        return DB::transaction(function () use ($source, $profile, $run, $changeReason): SourceProfile {
            Source::query()->whereKey($source->id)->lockForUpdate()->first();

            $version = ((int) $source->profiles()->max('version')) + 1;

            // The stored JSON is made consistent with its row before validation
            // so a candidate can never claim to be ACTIVE or belong elsewhere.
            $profile['schema_version'] = 1;
            $profile['source_key'] = $source->key;
            $profile['profile_version'] = $version;
            $profile['status'] = ProfileStatus::PendingApproval->value;
            $profile['approved_at'] = null;
            $profile['approved_by'] = null;

            $this->profiles->assertValid($profile);

            return $source->profiles()->create([
                'version' => $version,
                'schema_version' => 1,
                'status' => ProfileStatus::PendingApproval,
                'profile_json' => $profile,
                'created_by_run_id' => $run?->id,
                'change_reason' => $changeReason,
            ]);
        });
    }

    /**
     * record_health_observation: one metric sample with its baseline.
     */
    public function recordHealthObservation(Source $source, ?AcquisitionRun $run, string $metric, float $value, ?float $baseline, ?string $status, CarbonImmutable $observedAt): HealthObservation
    {
        return HealthObservation::query()->create([
            'source_id' => $source->id,
            'run_id' => $run?->id,
            'metric' => $metric,
            'value' => $value,
            'baseline' => $baseline,
            'status' => $status,
            'observed_at' => $observedAt,
        ]);
    }
}

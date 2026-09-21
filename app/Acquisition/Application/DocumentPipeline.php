<?php

namespace App\Acquisition\Application;

use App\Acquisition\Domain\Identity\StableKey;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Domain\Models\Source;
use App\Acquisition\Domain\Models\SourceProfile;
use App\Acquisition\Tools\Http\FetchResult;
use App\Acquisition\Tools\Normalize\NormalizeDocumentTool;
use App\Acquisition\Tools\Normalize\SourceContext;
use App\Acquisition\Tools\Parsers\ParserResolver;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\ToolError;

/**
 * The deterministic half of monitoring: turns one successful fetch into
 * RAW -> parsed -> NORMALIZED -> identity -> revision, all through the
 * Storage Tool. The RAW artifact is stored before anything is interpreted,
 * so a parser failure still leaves the original behind (plan §3.4).
 */
final class DocumentPipeline
{
    public function __construct(
        private StorageTool $storage,
        private ParserResolver $parsers,
        private NormalizeDocumentTool $normalizer,
    ) {}

    /**
     * @throws ToolError after recording the failed observation
     */
    public function ingest(AcquisitionRun $run, Source $source, ?SourceProfile $profile, FetchResult $fetch, ?string $feedGuid = null, ?string $documentType = null, ?string $feedPublishedAt = null): IngestOutcome
    {
        $raw = $this->storage->storeRawArtifact($fetch);

        try {
            $parserId = $this->parsers->parserIdFor($fetch->declaredMediaType ?? $fetch->detectedMediaType ?? 'application/octet-stream', $profile);
            $parsed = $this->parsers->parse($parserId, $fetch->body, $fetch->finalUrl);
            $normalized = $this->normalizer->normalize($parsed, self::contextFor($source, $profile, $fetch, $raw->sha256, $raw->blob_uri, $feedGuid, $documentType, $feedPublishedAt));

            $document = $this->storage->upsertDocumentIdentity(
                $source,
                new StableKey($normalized->stableKey, $normalized->identityRule),
                $normalized->canonicalUrl,
                $documentType ?? $normalized->documentType,
                $fetch->requestedUrl,
                $fetch->retrievedAt,
            );
            $append = $this->storage->appendDocumentRevision($document, $raw, $run, $fetch->retrievedAt);
            $artifact = $this->storage->storeNormalizedArtifact($append->revision, $normalized, $run);
        } catch (ToolError $error) {
            $this->storage->recordFetchObservation($run, $source, $fetch->requestedUrl, $fetch, $raw, $error);

            throw $error;
        }

        $this->storage->recordFetchObservation($run, $source, $fetch->requestedUrl, $fetch, $raw);

        $change = match (true) {
            ! $append->created => IngestOutcome::UNCHANGED,
            $append->revision->revision_no === 1 => IngestOutcome::NEW,
            default => IngestOutcome::REVISED,
        };

        return new IngestOutcome($raw, $document, $append->revision, $artifact, $change, (bool) $normalized->quality['passed']);
    }

    /**
     * Build the normalizer's context from the fetch and the profile's
     * quality expectations (defaults when there is no profile).
     */
    public static function contextFor(Source $source, ?SourceProfile $profile, FetchResult $fetch, string $rawSha256, string $rawBlobUri, ?string $feedGuid, ?string $documentType, ?string $feedPublishedAt = null): SourceContext
    {
        /** @var array<string, mixed> $expectations */
        $expectations = $profile?->profile_json['quality_expectations'] ?? [];
        /** @var list<string> $strip */
        $strip = $profile?->profile_json['crawl_policy']['strip_query_params'] ?? [];

        return new SourceContext(
            sourceKey: $source->key,
            requestedUrl: $fetch->requestedUrl,
            finalUrl: $fetch->finalUrl,
            retrievedAt: $fetch->retrievedAt,
            mediaType: $fetch->declaredMediaType ?? $fetch->detectedMediaType ?? 'application/octet-stream',
            rawSha256: $rawSha256,
            rawBlobUri: $rawBlobUri,
            feedGuid: $feedGuid,
            documentType: $documentType,
            requiredFields: array_values($expectations['required_fields'] ?? ['canonical_url', 'title']),
            minimumTextCharacters: (int) ($expectations['minimum_text_characters'] ?? 200),
            stripQueryParameters: $strip,
            feedPublishedAt: $feedPublishedAt,
        );
    }
}

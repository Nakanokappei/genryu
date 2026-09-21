<?php

namespace App\Acquisition\Tools\Normalize;

use App\Acquisition\Tools\RequestValidation;
use Carbon\CarbonImmutable;

/**
 * What Normalize needs to know about where a parsed artifact came from:
 * the source, the fetch, the RAW artifact, and the profile's quality
 * expectations. Everything here ends up in the document's provenance.
 */
final readonly class SourceContext
{
    /**
     * @param  list<string>  $requiredFields
     * @param  list<string>  $stripQueryParameters
     */
    public function __construct(
        public string $sourceKey,
        public string $requestedUrl,
        public string $finalUrl,
        public CarbonImmutable $retrievedAt,
        public string $mediaType,
        public string $rawSha256,
        public ?string $rawBlobUri = null,
        public ?string $feedGuid = null,
        public ?string $documentType = null,
        public array $requiredFields = ['canonical_url', 'title'],
        public int $minimumTextCharacters = 200,
        public array $stripQueryParameters = [],
        public ?string $feedPublishedAt = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'source_key' => ['required', 'string', 'regex:/^[a-z0-9_-]+$/'],
            'requested_url' => ['required', 'string', 'url:http,https'],
            'final_url' => ['required', 'string', 'url:http,https'],
            'retrieved_at' => ['required', 'date'],
            'media_type' => ['required', 'string'],
            'raw_sha256' => ['required', 'string', 'size:64'],
            'raw_blob_uri' => ['nullable', 'string'],
            'feed_guid' => ['nullable', 'string'],
            'feed_published_at' => ['nullable', 'date'],
            'document_type' => ['nullable', 'string', 'max:32'],
            'quality_expectations' => ['sometimes', 'array'],
            'quality_expectations.required_fields' => ['sometimes', 'array'],
            'quality_expectations.required_fields.*' => ['string'],
            'quality_expectations.minimum_text_characters' => ['sometimes', 'integer', 'min:0'],
            'strip_query_params' => ['sometimes', 'array'],
            'strip_query_params.*' => ['string'],
        ]);

        /** @var array<string, mixed> $expectations */
        $expectations = $data['quality_expectations'] ?? [];

        return new self(
            sourceKey: $data['source_key'],
            requestedUrl: $data['requested_url'],
            finalUrl: $data['final_url'],
            retrievedAt: CarbonImmutable::parse($data['retrieved_at'])->utc(),
            mediaType: $data['media_type'],
            rawSha256: $data['raw_sha256'],
            rawBlobUri: $data['raw_blob_uri'] ?? null,
            feedGuid: $data['feed_guid'] ?? null,
            documentType: $data['document_type'] ?? null,
            requiredFields: array_values($expectations['required_fields'] ?? ['canonical_url', 'title']),
            minimumTextCharacters: (int) ($expectations['minimum_text_characters'] ?? 200),
            stripQueryParameters: array_values($data['strip_query_params'] ?? []),
            feedPublishedAt: isset($data['feed_published_at']) ? CarbonImmutable::parse($data['feed_published_at'])->utc()->toIso8601ZuluString() : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'source_key' => $this->sourceKey,
            'requested_url' => $this->requestedUrl,
            'final_url' => $this->finalUrl,
            'retrieved_at' => $this->retrievedAt->toIso8601ZuluString(),
            'media_type' => $this->mediaType,
            'raw_sha256' => $this->rawSha256,
            'raw_blob_uri' => $this->rawBlobUri,
            'feed_guid' => $this->feedGuid,
            'feed_published_at' => $this->feedPublishedAt,
            'document_type' => $this->documentType,
            'quality_expectations' => ['required_fields' => $this->requiredFields, 'minimum_text_characters' => $this->minimumTextCharacters],
            'strip_query_params' => $this->stripQueryParameters,
        ];
    }
}

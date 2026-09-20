<?php

namespace App\Acquisition\Tools\Normalize;

use App\Acquisition\Tools\ToolResult;

/**
 * The common Document Model (plan §7.6). Field order is part of the
 * contract: toMarkdown() emits front matter in exactly this order so equal
 * inputs under equal versions produce byte-identical NORMALIZED artifacts.
 */
final readonly class NormalizedDocument implements ToolResult
{
    /**
     * @param  list<string>  $outboundLinks
     * @param  array{text_characters: int, required_fields_missing: list<string>, passed: bool}  $quality
     * @param  list<string>  $warnings
     * @param  array<string, mixed>  $provenance
     */
    public function __construct(
        public string $source,
        public string $stableKey,
        public string $identityRule,
        public ?string $canonicalUrl,
        public string $sourceUrl,
        public string $requestedUrl,
        public ?string $title,
        public ?string $documentType,
        public ?string $publishedAt,
        public ?string $updatedAt,
        public string $retrievedAt,
        public ?string $language,
        public string $contentType,
        public string $parserId,
        public string $normalizerVersion,
        public string $rawSha256,
        public ?string $rawBlobUri,
        public array $outboundLinks,
        public string $body,
        public array $quality,
        public array $warnings,
        public array $provenance,
    ) {}

    /**
     * Front matter fields in contract order.
     *
     * @return array<string, mixed>
     */
    public function frontMatter(): array
    {
        return [
            'schema_version' => 1,
            'source' => $this->source,
            'stable_key' => $this->stableKey,
            'identity_rule' => $this->identityRule,
            'canonical_url' => $this->canonicalUrl,
            'source_url' => $this->sourceUrl,
            'requested_url' => $this->requestedUrl,
            'title' => $this->title,
            'document_type' => $this->documentType,
            'published_at' => $this->publishedAt,
            'updated_at' => $this->updatedAt,
            'retrieved_at' => $this->retrievedAt,
            'language' => $this->language,
            'content_type' => $this->contentType,
            'parser_id' => $this->parserId,
            'normalizer_version' => $this->normalizerVersion,
            'raw_sha256' => $this->rawSha256,
            'raw_blob_uri' => $this->rawBlobUri,
            'outbound_links' => $this->outboundLinks,
            'quality' => $this->quality,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * The NORMALIZED artifact bytes: YAML front matter, then the body.
     */
    public function toMarkdown(): string
    {
        return "---\n".FrontMatter::emit($this->frontMatter())."---\n\n".rtrim($this->body)."\n";
    }

    /**
     * SHA-256 of the artifact bytes (plan §7.6).
     */
    public function sha256(): string
    {
        return hash('sha256', $this->toMarkdown());
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            ...$this->frontMatter(),
            'body' => $this->body,
            'provenance' => $this->provenance,
            'sha256' => $this->sha256(),
        ];
    }
}

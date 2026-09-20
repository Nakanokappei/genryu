<?php

namespace App\Acquisition\Tools\Http;

use App\Acquisition\Tools\ToolResult;
use Carbon\CarbonImmutable;

/**
 * Output of fetch_url. Carries the body bytes for PHP callers (the Storage
 * Tool turns them into a RAW artifact); toArray() deliberately replaces the
 * body with its hash and length so bodies never travel over the Agent bridge
 * or into logs.
 */
final readonly class FetchResult implements ToolResult
{
    /**
     * @param  list<string>  $redirectChain  every URL after the requested one, in order
     * @param  array<string, string>  $headers  selected response headers, lower-cased names
     */
    public function __construct(
        public string $requestedUrl,
        public string $finalUrl,
        public array $redirectChain,
        public int $status,
        public array $headers,
        public ?string $declaredMediaType,
        public ?string $detectedMediaType,
        public bool $contentTypeMismatch,
        public CarbonImmutable $retrievedAt,
        public int $durationMs,
        public int $attempts,
        public string $body,
        public bool $notModified,
    ) {}

    /**
     * SHA-256 of the body bytes; the future RAW artifact address.
     */
    public function sha256(): string
    {
        return hash('sha256', $this->body);
    }

    public function etag(): ?string
    {
        return $this->headers['etag'] ?? null;
    }

    public function lastModified(): ?string
    {
        return $this->headers['last-modified'] ?? null;
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'requested_url' => $this->requestedUrl,
            'final_url' => $this->finalUrl,
            'redirect_chain' => $this->redirectChain,
            'status' => $this->status,
            'not_modified' => $this->notModified,
            'headers' => $this->headers,
            'declared_media_type' => $this->declaredMediaType,
            'detected_media_type' => $this->detectedMediaType,
            'content_type_mismatch' => $this->contentTypeMismatch,
            'retrieved_at' => $this->retrievedAt->toIso8601ZuluString(),
            'duration_ms' => $this->durationMs,
            'attempts' => $this->attempts,
            'body_bytes' => strlen($this->body),
            'body_sha256' => $this->sha256(),
            'etag' => $this->etag(),
            'last_modified' => $this->lastModified(),
        ];
    }
}

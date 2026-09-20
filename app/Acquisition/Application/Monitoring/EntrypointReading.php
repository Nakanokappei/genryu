<?php

namespace App\Acquisition\Application\Monitoring;

/**
 * What one entrypoint fetch told us this run: the health evaluator turns
 * these into observations and drift evidence.
 */
final class EntrypointReading
{
    /**
     * @param  string  $type  rss | atom | sitemap | sitemap_index | index | api
     */
    public function __construct(
        public string $url,
        public string $type,
        public ?int $status = null,
        public ?int $entryCount = null,
        public ?string $mediaType = null,
        public bool $contentTypeChanged = false,
        public ?string $error = null,
    ) {}

    public function reachable(): bool
    {
        return $this->status !== null && ($this->status === 304 || ($this->status >= 200 && $this->status < 300));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['url' => $this->url, 'type' => $this->type, 'status' => $this->status, 'entry_count' => $this->entryCount, 'media_type' => $this->mediaType, 'content_type_changed' => $this->contentTypeChanged, 'error' => $this->error];
    }
}

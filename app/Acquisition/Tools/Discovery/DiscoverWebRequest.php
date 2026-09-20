<?php

namespace App\Acquisition\Tools\Discovery;

use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\ToolRequest;

/**
 * Input of discover_web (plan §7.1). Every limit is explicit so the Agent
 * can never start an unbounded crawl.
 */
final readonly class DiscoverWebRequest implements ToolRequest
{
    /**
     * @param  list<string>  $allowedHosts
     * @param  list<string>  $hints  URL fragments worth exploring first
     */
    public function __construct(
        public string $seedUrl,
        public array $allowedHosts,
        public int $maxDepth,
        public int $maxUrls,
        public int $maxSeconds,
        public int $requestsPerMinute,
        public array $hints = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'seed_url' => ['required', 'string', 'url:http,https'],
            'allowed_hosts' => ['required', 'array', 'min:1'],
            'allowed_hosts.*' => ['required', 'string'],
            'max_depth' => ['sometimes', 'integer', 'min:0', 'max:5'],
            'max_urls' => ['sometimes', 'integer', 'min:1', 'max:500'],
            'max_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'requests_per_minute' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'hints' => ['sometimes', 'array', 'max:20'],
            'hints.*' => ['string', 'max:200'],
        ]);

        /** @var array<string, int> $defaults */
        $defaults = config('acquisition.discovery');
        /** @var list<string> $hosts */
        $hosts = $data['allowed_hosts'];

        return new self(
            seedUrl: $data['seed_url'],
            allowedHosts: array_values(array_unique(array_map('strtolower', $hosts))),
            maxDepth: (int) ($data['max_depth'] ?? $defaults['max_depth']),
            maxUrls: (int) ($data['max_urls'] ?? $defaults['max_urls']),
            maxSeconds: (int) ($data['max_seconds'] ?? $defaults['max_seconds']),
            requestsPerMinute: (int) ($data['requests_per_minute'] ?? $defaults['requests_per_minute']),
            hints: array_values($data['hints'] ?? []),
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'seed_url' => $this->seedUrl,
            'allowed_hosts' => $this->allowedHosts,
            'max_depth' => $this->maxDepth,
            'max_urls' => $this->maxUrls,
            'max_seconds' => $this->maxSeconds,
            'requests_per_minute' => $this->requestsPerMinute,
            'hints' => $this->hints,
        ];
    }
}

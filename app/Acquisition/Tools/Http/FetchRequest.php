<?php

namespace App\Acquisition\Tools\Http;

use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\ToolRequest;

/**
 * Input of fetch_url (plan §7.2).
 */
final readonly class FetchRequest implements ToolRequest
{
    /**
     * @param  list<string>  $allowedHosts  lower-cased hosts the fetch may touch, redirects included
     */
    public function __construct(
        public string $url,
        public array $allowedHosts,
        public ?string $ifNoneMatch,
        public ?string $ifModifiedSince,
        public int $timeoutSeconds,
        public int $maxBodyBytes,
        public int $maxRedirects,
        public int $maxAttempts,
    ) {}

    /**
     * Build from the wire payload, applying config defaults for omitted limits.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'url' => ['required', 'string', 'max:2048', 'url:http,https'],
            'allowed_hosts' => ['required', 'array', 'min:1'],
            'allowed_hosts.*' => ['required', 'string', 'max:253'],
            'conditional_headers' => ['sometimes', 'array'],
            'conditional_headers.etag' => ['nullable', 'string', 'max:1024'],
            'conditional_headers.last_modified' => ['nullable', 'string', 'max:64'],
            'limits' => ['sometimes', 'array'],
            'limits.timeout_seconds' => ['integer', 'min:1', 'max:300'],
            'limits.max_body_bytes' => ['integer', 'min:1'],
            'limits.max_redirects' => ['integer', 'min:0', 'max:20'],
            'limits.max_attempts' => ['integer', 'min:1', 'max:5'],
        ]);

        /** @var array<string, int> $defaults */
        $defaults = config('acquisition.fetch');
        /** @var array<string, int> $limits */
        $limits = $data['limits'] ?? [];
        /** @var array<string, string|null> $conditional */
        $conditional = $data['conditional_headers'] ?? [];
        /** @var list<string> $hosts */
        $hosts = $data['allowed_hosts'];

        return new self(
            url: $data['url'],
            allowedHosts: array_values(array_unique(array_map('strtolower', $hosts))),
            ifNoneMatch: $conditional['etag'] ?? null,
            ifModifiedSince: $conditional['last_modified'] ?? null,
            timeoutSeconds: $limits['timeout_seconds'] ?? $defaults['timeout_seconds'],
            maxBodyBytes: $limits['max_body_bytes'] ?? $defaults['max_body_bytes'],
            maxRedirects: $limits['max_redirects'] ?? $defaults['max_redirects'],
            maxAttempts: $limits['max_attempts'] ?? $defaults['max_attempts'],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'allowed_hosts' => $this->allowedHosts,
            'conditional_headers' => ['etag' => $this->ifNoneMatch, 'last_modified' => $this->ifModifiedSince],
            'limits' => [
                'timeout_seconds' => $this->timeoutSeconds,
                'max_body_bytes' => $this->maxBodyBytes,
                'max_redirects' => $this->maxRedirects,
                'max_attempts' => $this->maxAttempts,
            ],
        ];
    }
}

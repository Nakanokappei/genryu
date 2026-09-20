<?php

namespace App\Acquisition\Tools\Html;

use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\ToolRequest;

/**
 * Input of parse_html (plan §7.3): the RAW bytes plus the URL they were
 * served from, which anchors relative links.
 */
final readonly class ParseHtmlRequest implements ToolRequest
{
    public function __construct(
        public string $html,
        public string $url,
        public int $parserVersion = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'html' => ['present', 'string'],
            'url' => ['required', 'string', 'url:http,https'],
            'parser_version' => ['sometimes', 'integer', 'in:1'],
        ]);

        return new self($data['html'], $data['url'], (int) ($data['parser_version'] ?? 1));
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        // The body is represented by its hash so digests stay small and
        // bodies stay out of the audit trail.
        return ['html_sha256' => hash('sha256', $this->html), 'url' => $this->url, 'parser_version' => $this->parserVersion];
    }
}

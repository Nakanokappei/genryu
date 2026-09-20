<?php

namespace App\Acquisition\Tools\Xml;

use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\ToolRequest;

/**
 * Input of parse_xml (plan §7.4).
 */
final readonly class ParseXmlRequest implements ToolRequest
{
    public const KINDS = ['rss', 'atom', 'sitemap', 'sitemap_index'];

    public function __construct(
        public string $xml,
        public string $url,
        public ?string $expectedKind = null,
        public int $parserVersion = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'xml' => ['present', 'string'],
            'url' => ['required', 'string', 'url:http,https'],
            'expected_kind' => ['nullable', 'string', 'in:'.implode(',', self::KINDS)],
            'parser_version' => ['sometimes', 'integer', 'in:1'],
        ]);

        return new self($data['xml'], $data['url'], $data['expected_kind'] ?? null, (int) ($data['parser_version'] ?? 1));
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return ['xml_sha256' => hash('sha256', $this->xml), 'url' => $this->url, 'expected_kind' => $this->expectedKind, 'parser_version' => $this->parserVersion];
    }
}

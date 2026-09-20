<?php

namespace App\Acquisition\Tools\Pdf;

use App\Acquisition\Tools\RequestValidation;
use App\Acquisition\Tools\ToolRequest;

/**
 * Input of parse_pdf (plan §7.5): the RAW bytes and the URL they came from.
 */
final readonly class ParsePdfRequest implements ToolRequest
{
    public function __construct(
        public string $pdf,
        public string $url,
        public int $parserVersion = 1,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $data = RequestValidation::validate($payload, [
            'pdf' => ['present', 'string'],
            'url' => ['required', 'string', 'url:http,https'],
            'parser_version' => ['sometimes', 'integer', 'in:1'],
        ]);

        return new self($data['pdf'], $data['url'], (int) ($data['parser_version'] ?? 1));
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return ['pdf_sha256' => hash('sha256', $this->pdf), 'url' => $this->url, 'parser_version' => $this->parserVersion];
    }
}

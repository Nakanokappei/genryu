<?php

namespace App\Acquisition\Tools\Pdf;

use App\Acquisition\Tools\Parsers\ParserRegistry;
use App\Acquisition\Tools\ToolResult;

/**
 * Output of parse_pdf. Text comes from the PDF's own text layer; there is
 * no OCR in Phase 0, so a scan never reaches this object (it fails with
 * UNSUPPORTED_SCANNED_PDF instead).
 */
final readonly class ParsedPdf implements ToolResult
{
    /**
     * @param  array<string, string>  $metadata  Info dictionary, lower-cased keys
     * @param  list<array{number: int, text: string}>  $pages
     * @param  array<string, mixed>  $quality
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $metadata,
        public int $pageCount,
        public array $pages,
        public string $markdown,
        public string $extractionMethod,
        public bool $encrypted,
        public array $quality,
        public array $warnings,
        public string $parserId = ParserRegistry::PDF_TEXT_V1,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            metadata: $data['metadata'] ?? [],
            pageCount: (int) ($data['page_count'] ?? 0),
            pages: $data['pages'] ?? [],
            markdown: $data['markdown'] ?? '',
            extractionMethod: $data['extraction_method'] ?? 'text_layer',
            encrypted: (bool) ($data['encrypted'] ?? false),
            quality: $data['quality'] ?? [],
            warnings: $data['warnings'] ?? [],
            parserId: $data['parser_id'] ?? ParserRegistry::PDF_TEXT_V1,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'parser_id' => $this->parserId,
            'metadata' => $this->metadata,
            'page_count' => $this->pageCount,
            'pages' => $this->pages,
            'markdown' => $this->markdown,
            'extraction_method' => $this->extractionMethod,
            'encrypted' => $this->encrypted,
            'quality' => $this->quality,
            'warnings' => $this->warnings,
        ];
    }
}

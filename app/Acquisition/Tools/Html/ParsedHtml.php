<?php

namespace App\Acquisition\Tools\Html;

use App\Acquisition\Tools\Parsers\ParserRegistry;
use App\Acquisition\Tools\ToolResult;

/**
 * Output of parse_html. Dates are candidates with a source and confidence,
 * never a single guessed value (plan §7.3); Normalize picks later and keeps
 * the candidates as provenance.
 */
final readonly class ParsedHtml implements ToolResult
{
    /**
     * @param  list<array{kind: string, value: string|null, raw: string, source: string, confidence: float}>  $dateCandidates
     * @param  array<string, string>  $metadata
     * @param  array<string, string>  $openGraph
     * @param  list<array<string, mixed>>  $jsonLd
     * @param  list<array{level: int, text: string}>  $headings
     * @param  list<array{url: string, text: string}>  $links
     * @param  array<string, mixed>  $quality
     * @param  list<string>  $warnings
     */
    public function __construct(
        public ?string $title,
        public ?string $canonicalUrl,
        public ?string $language,
        public ?string $author,
        public array $dateCandidates,
        public array $metadata,
        public array $openGraph,
        public array $jsonLd,
        public array $headings,
        public array $links,
        public string $markdown,
        public string $text,
        public string $mainContentSelector,
        public array $quality,
        public array $warnings,
        public string $parserId = ParserRegistry::HTML_GENERIC_V1,
    ) {}

    /**
     * Rebuild from the wire form (the Agent bridge hands parsed results back
     * to normalize_document this way).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? null,
            canonicalUrl: $data['canonical_url'] ?? null,
            language: $data['language'] ?? null,
            author: $data['author'] ?? null,
            dateCandidates: $data['date_candidates'] ?? [],
            metadata: $data['metadata'] ?? [],
            openGraph: $data['open_graph'] ?? [],
            jsonLd: $data['json_ld'] ?? [],
            headings: $data['headings'] ?? [],
            links: $data['links'] ?? [],
            markdown: $data['markdown'] ?? '',
            text: $data['text'] ?? '',
            mainContentSelector: $data['main_content_selector'] ?? 'body',
            quality: $data['quality'] ?? [],
            warnings: $data['warnings'] ?? [],
            parserId: $data['parser_id'] ?? ParserRegistry::HTML_GENERIC_V1,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'parser_id' => $this->parserId,
            'title' => $this->title,
            'canonical_url' => $this->canonicalUrl,
            'language' => $this->language,
            'author' => $this->author,
            'date_candidates' => $this->dateCandidates,
            'metadata' => $this->metadata,
            'open_graph' => $this->openGraph,
            'json_ld' => $this->jsonLd,
            'headings' => $this->headings,
            'links' => $this->links,
            'markdown' => $this->markdown,
            'text' => $this->text,
            'main_content_selector' => $this->mainContentSelector,
            'quality' => $this->quality,
            'warnings' => $this->warnings,
        ];
    }
}

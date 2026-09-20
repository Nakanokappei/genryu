<?php

namespace App\Acquisition\Tools\Xml;

use App\Acquisition\Tools\Parsers\ParserRegistry;
use App\Acquisition\Tools\ToolResult;

/**
 * Output of parse_xml: one shape for RSS, Atom, sitemaps and sitemap
 * indexes. Entries carry the fields identity and monitoring need (GUID,
 * URL, dates); bodies are deliberately not extracted here.
 */
final readonly class ParsedXml implements ToolResult
{
    /**
     * @param  array{title: string|null, url: string|null, updated: string|null}  $feed
     * @param  list<array{id: string|null, url: string|null, title: string|null, published: string|null, updated: string|null, summary: string|null}>  $entries
     * @param  list<array{url: string, updated: string|null}>  $children  child sitemaps of an index
     * @param  array{next: string|null}  $pagination
     * @param  array<string, mixed>  $quality
     * @param  list<string>  $warnings
     */
    public function __construct(
        public string $kind,
        public array $feed,
        public array $entries,
        public array $children,
        public array $pagination,
        public array $quality,
        public array $warnings,
        public string $parserId = ParserRegistry::XML_FEED_V1,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            kind: $data['kind'] ?? 'xml',
            feed: $data['feed'] ?? ['title' => null, 'url' => null, 'updated' => null],
            entries: $data['entries'] ?? [],
            children: $data['children'] ?? [],
            pagination: $data['pagination'] ?? ['next' => null],
            quality: $data['quality'] ?? [],
            warnings: $data['warnings'] ?? [],
            parserId: $data['parser_id'] ?? ParserRegistry::XML_FEED_V1,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function toArray(): array
    {
        return [
            'parser_id' => $this->parserId,
            'kind' => $this->kind,
            'feed' => $this->feed,
            'entries' => $this->entries,
            'children' => $this->children,
            'pagination' => $this->pagination,
            'quality' => $this->quality,
            'warnings' => $this->warnings,
        ];
    }
}

<?php

namespace App\Acquisition\Tools\Xml;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use InvalidArgumentException;

/**
 * XML / Feed Tool, parser xml.feed@1 (plan §7.4). Handles RSS 2.0, Atom,
 * sitemaps and sitemap indexes with namespaces, refuses external entities
 * outright (AT-10), and never reports malformed XML as an empty success.
 */
final class ParseXmlTool implements Tool
{
    private const NS_ATOM = 'http://www.w3.org/2005/Atom';

    private const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';

    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    /**
     * @var array{title: string|null, url: string|null, updated: string|null}
     */
    private const EMPTY_FEED = ['title' => null, 'url' => null, 'updated' => null];

    public function name(): string
    {
        return 'parse_xml';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return ParseXmlRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof ParseXmlRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'parse_xml expects a ParseXmlRequest.');
        }

        return $this->parse($request->xml, $request->url, $request->expectedKind);
    }

    /**
     * Parse XML served from $url. Usable directly by PHP callers.
     */
    public function parse(string $xml, string $url, ?string $expectedKind = null): ParsedXml
    {
        $this->rejectEntityDeclarations($xml, $url);

        $document = $this->load($xml, $url);
        $root = $document->documentElement;

        if ($root === null) {
            throw new ToolError(ErrorCode::ParseFailed, 'XML has no root element.', ['url' => $url]);
        }

        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('atom', self::NS_ATOM);
        $xpath->registerNamespace('sm', self::NS_SITEMAP);
        $xpath->registerNamespace('dc', self::NS_DC);

        $warnings = [];
        $kind = $this->kind($root);

        $result = match ($kind) {
            'rss' => $this->rss($xpath, $url, $warnings),
            'atom' => $this->atom($xpath, $url, $warnings),
            'sitemap' => $this->sitemap($xpath, $url, $warnings),
            'sitemap_index' => $this->sitemapIndex($xpath, $url, $warnings),
            default => $this->generic($root, $warnings),
        };

        if ($expectedKind !== null && $expectedKind !== $kind) {
            $warnings[] = "Expected {$expectedKind} but document is {$kind}.";
        }

        if ($kind !== 'xml' && $result['entries'] === [] && $result['children'] === []) {
            $warnings[] = "{$kind} document contains no entries.";
        }

        $quality = [
            'entry_count' => count($result['entries']),
            'child_count' => count($result['children']),
            'entries_missing_url' => count(array_filter($result['entries'], static fn (array $entry): bool => $entry['url'] === null)),
            'entries_missing_id' => count(array_filter($result['entries'], static fn (array $entry): bool => $entry['id'] === null)),
            'kind_matches_expected' => $expectedKind === null || $expectedKind === $kind,
        ];

        return new ParsedXml($kind, $result['feed'], $result['entries'], $result['children'], $result['pagination'], $quality, $warnings);
    }

    /**
     * AT-10: any DOCTYPE that declares entities or points at an external
     * subset is rejected before the parser ever sees it. A feed never needs
     * one, so this loses nothing.
     */
    private function rejectEntityDeclarations(string $xml, string $url): void
    {
        $prolog = substr($xml, 0, 4096);

        if (stripos($prolog, '<!ENTITY') !== false || preg_match('/<!DOCTYPE\b[^>]*(\[|\bSYSTEM\b|\bPUBLIC\b)/i', $prolog) === 1) {
            throw new ToolError(ErrorCode::XmlExternalEntityRejected, 'XML declares entities or an external DTD; refusing to parse.', ['url' => $url]);
        }
    }

    /**
     * Load with network access disabled and a null entity loader as a second
     * line of defence. Malformed XML is PARSE_FAILED, never an empty feed.
     */
    private function load(string $xml, string $url): DOMDocument
    {
        libxml_set_external_entity_loader(static fn (): null => null);
        $previousErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            $document = new DOMDocument;
            $loaded = trim($xml) !== '' && $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA | LIBXML_NOBLANKS);

            if (! $loaded) {
                $first = libxml_get_errors()[0] ?? null;
                $reason = $first !== null ? trim($first->message)." (line {$first->line})" : 'empty document';

                throw new ToolError(ErrorCode::ParseFailed, "XML is not well-formed: {$reason}", ['url' => $url]);
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            // null restores libxml's default loader.
            libxml_set_external_entity_loader(null);
        }
    }

    private function kind(DOMElement $root): string
    {
        return match (true) {
            $root->localName === 'rss' => 'rss',
            $root->localName === 'feed' && $root->namespaceURI === self::NS_ATOM => 'atom',
            $root->localName === 'urlset' && $root->namespaceURI === self::NS_SITEMAP => 'sitemap',
            $root->localName === 'sitemapindex' && $root->namespaceURI === self::NS_SITEMAP => 'sitemap_index',
            default => 'xml',
        };
    }

    /**
     * @param  list<string>  $warnings
     * @return array{feed: array{title: string|null, url: string|null, updated: string|null}, entries: list<array{id: string|null, url: string|null, title: string|null, published: string|null, updated: string|null, summary: string|null}>, children: list<array{url: string, updated: string|null}>, pagination: array{next: string|null}}
     */
    private function rss(DOMXPath $xpath, string $url, array &$warnings): array
    {
        $entries = [];

        foreach ($this->elements($xpath, '/rss/channel/item') as $index => $item) {
            $entries[] = [
                'id' => $this->text($xpath, 'guid', $item),
                'url' => $this->absolute($this->text($xpath, 'link', $item) ?? $this->permalinkGuid($xpath, $item), $url),
                'title' => $this->text($xpath, 'title', $item),
                'published' => $this->date($this->text($xpath, 'pubDate', $item) ?? $this->text($xpath, 'dc:date', $item), "item[{$index}]", $warnings),
                'updated' => null,
                'summary' => $this->text($xpath, 'description', $item),
            ];
        }

        return [
            'feed' => [
                'title' => $this->text($xpath, '/rss/channel/title'),
                'url' => $this->absolute($this->text($xpath, '/rss/channel/link'), $url),
                'updated' => $this->date($this->text($xpath, '/rss/channel/lastBuildDate') ?? $this->text($xpath, '/rss/channel/pubDate'), 'channel', $warnings),
            ],
            'entries' => $entries,
            'children' => [],
            'pagination' => ['next' => null],
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{feed: array{title: string|null, url: string|null, updated: string|null}, entries: list<array{id: string|null, url: string|null, title: string|null, published: string|null, updated: string|null, summary: string|null}>, children: list<array{url: string, updated: string|null}>, pagination: array{next: string|null}}
     */
    private function atom(DOMXPath $xpath, string $url, array &$warnings): array
    {
        $entries = [];

        foreach ($this->elements($xpath, '/atom:feed/atom:entry') as $index => $entry) {
            $entries[] = [
                'id' => $this->text($xpath, 'atom:id', $entry),
                'url' => $this->absolute($this->atomLink($xpath, $entry, 'alternate') ?? $this->atomLink($xpath, $entry, null), $url),
                'title' => $this->text($xpath, 'atom:title', $entry),
                'published' => $this->date($this->text($xpath, 'atom:published', $entry), "entry[{$index}].published", $warnings),
                'updated' => $this->date($this->text($xpath, 'atom:updated', $entry), "entry[{$index}].updated", $warnings),
                'summary' => $this->text($xpath, 'atom:summary', $entry),
            ];
        }

        $feedNode = $this->elements($xpath, '/atom:feed')[0] ?? null;

        return [
            'feed' => [
                'title' => $this->text($xpath, '/atom:feed/atom:title'),
                'url' => $this->absolute($feedNode !== null ? $this->atomLink($xpath, $feedNode, 'alternate') : null, $url),
                'updated' => $this->date($this->text($xpath, '/atom:feed/atom:updated'), 'feed.updated', $warnings),
            ],
            'entries' => $entries,
            'children' => [],
            'pagination' => ['next' => $this->absolute($feedNode !== null ? $this->atomLink($xpath, $feedNode, 'next') : null, $url)],
        ];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{feed: array{title: string|null, url: string|null, updated: string|null}, entries: list<array{id: string|null, url: string|null, title: string|null, published: string|null, updated: string|null, summary: string|null}>, children: list<array{url: string, updated: string|null}>, pagination: array{next: string|null}}
     */
    private function sitemap(DOMXPath $xpath, string $url, array &$warnings): array
    {
        $entries = [];

        foreach ($this->elements($xpath, '/sm:urlset/sm:url') as $index => $node) {
            $loc = $this->absolute($this->text($xpath, 'sm:loc', $node), $url);

            if ($loc === null) {
                $warnings[] = "sitemap url[{$index}] has no <loc>.";

                continue;
            }

            $entries[] = [
                'id' => null,
                'url' => $loc,
                'title' => null,
                'published' => null,
                'updated' => $this->date($this->text($xpath, 'sm:lastmod', $node), "url[{$index}].lastmod", $warnings),
                'summary' => null,
            ];
        }

        return ['feed' => ['title' => null, 'url' => $url, 'updated' => null], 'entries' => $entries, 'children' => [], 'pagination' => ['next' => null]];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{feed: array{title: string|null, url: string|null, updated: string|null}, entries: list<array{id: string|null, url: string|null, title: string|null, published: string|null, updated: string|null, summary: string|null}>, children: list<array{url: string, updated: string|null}>, pagination: array{next: string|null}}
     */
    private function sitemapIndex(DOMXPath $xpath, string $url, array &$warnings): array
    {
        $children = [];

        foreach ($this->elements($xpath, '/sm:sitemapindex/sm:sitemap') as $index => $node) {
            $loc = $this->absolute($this->text($xpath, 'sm:loc', $node), $url);

            if ($loc === null) {
                $warnings[] = "sitemapindex sitemap[{$index}] has no <loc>.";

                continue;
            }

            $children[] = ['url' => $loc, 'updated' => $this->date($this->text($xpath, 'sm:lastmod', $node), "sitemap[{$index}].lastmod", $warnings)];
        }

        return ['feed' => ['title' => null, 'url' => $url, 'updated' => null], 'entries' => [], 'children' => $children, 'pagination' => ['next' => null]];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{feed: array{title: string|null, url: string|null, updated: string|null}, entries: list<array{id: string|null, url: string|null, title: string|null, published: string|null, updated: string|null, summary: string|null}>, children: list<array{url: string, updated: string|null}>, pagination: array{next: string|null}}
     */
    private function generic(DOMElement $root, array &$warnings): array
    {
        $warnings[] = "Unrecognised XML document type: <{$root->localName}>".($root->namespaceURI !== null ? " in {$root->namespaceURI}" : '').'.';

        return ['feed' => self::EMPTY_FEED, 'entries' => [], 'children' => [], 'pagination' => ['next' => null]];
    }

    /**
     * Element matches of an XPath expression, in document order. Non-element
     * results (attributes, namespace nodes) are dropped.
     *
     * @return list<DOMElement>
     */
    private function elements(DOMXPath $xpath, string $expression, ?DOMNode $context = null): array
    {
        $list = $xpath->query($expression, $context);
        $elements = [];

        if ($list === false) {
            return $elements;
        }

        foreach ($list as $node) {
            if ($node instanceof DOMElement) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    /**
     * Trimmed text of the first matching element, or null when absent or empty.
     */
    private function text(DOMXPath $xpath, string $expression, ?DOMNode $context = null): ?string
    {
        $element = $this->elements($xpath, $expression, $context)[0] ?? null;

        if ($element === null) {
            return null;
        }

        $text = trim($element->textContent);

        return $text === '' ? null : $text;
    }

    /**
     * An RSS <guid isPermaLink="true"> doubles as the item URL.
     */
    private function permalinkGuid(DOMXPath $xpath, DOMElement $item): ?string
    {
        $guid = $this->elements($xpath, 'guid', $item)[0] ?? null;

        if ($guid !== null && strtolower($guid->getAttribute('isPermaLink')) !== 'false') {
            return trim($guid->textContent) ?: null;
        }

        return null;
    }

    /**
     * href of an Atom <link>; $rel null means "no rel attribute" (which the
     * spec treats as alternate).
     */
    private function atomLink(DOMXPath $xpath, DOMElement $context, ?string $rel): ?string
    {
        $expression = $rel === null ? 'atom:link[not(@rel)]' : "atom:link[@rel='{$rel}']";
        $link = $this->elements($xpath, $expression, $context)[0] ?? null;

        if ($link === null) {
            return null;
        }

        return trim($link->getAttribute('href')) ?: null;
    }

    private function absolute(?string $reference, string $base): ?string
    {
        if ($reference === null) {
            return null;
        }

        try {
            return UrlNormalizer::resolve($base, $reference);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  list<string>  $warnings
     */
    private function date(?string $raw, string $where, array &$warnings): ?string
    {
        if ($raw === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->utc()->toIso8601ZuluString();
        } catch (InvalidFormatException) {
            $warnings[] = "Unparseable date at {$where}: {$raw}";

            return null;
        }
    }
}

<?php

namespace App\Acquisition\Tools\Html;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Dom\Element;
use Dom\HTMLDocument;
use InvalidArgumentException;
use JsonException;
use League\HTMLToMarkdown\HtmlConverter;
use Throwable;

/**
 * HTML Tool, parser html.generic@1 (plan §7.3). Deterministic DOM and
 * metadata extraction on PHP's native HTML5 parser; no LLM, no network.
 * The RAW bytes are never modified: everything here works on a parsed copy.
 */
final class ParseHtmlTool implements Tool
{
    /**
     * Elements that are never main content. <header> is handled separately
     * because an <article> legitimately starts with one.
     */
    private const NOISE_SELECTOR = 'script, style, noscript, template, iframe, svg, canvas, form, nav, footer, aside, [hidden], [aria-hidden="true"]';

    /**
     * Where main content usually lives, most specific first.
     */
    private const MAIN_SELECTORS = ['main article', 'article', 'main', '[role="main"]', 'body'];

    /**
     * <meta> names that carry a publication date, with the confidence they earn.
     *
     * @var array<string, float>
     */
    private const PUBLISHED_META = [
        'article:published_time' => 0.9, 'datepublished' => 0.8, 'date' => 0.7, 'dc.date' => 0.7,
        'dc.date.issued' => 0.7, 'dcterms.created' => 0.7, 'pubdate' => 0.7, 'publish_date' => 0.7, 'article.published' => 0.7,
    ];

    /**
     * @var array<string, float>
     */
    private const UPDATED_META = [
        'article:modified_time' => 0.9, 'datemodified' => 0.8, 'dcterms.modified' => 0.7, 'last-modified' => 0.7, 'og:updated_time' => 0.7,
    ];

    public function name(): string
    {
        return 'parse_html';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return ParseHtmlRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof ParseHtmlRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'parse_html expects a ParseHtmlRequest.');
        }

        return $this->parse($request->html, $request->url);
    }

    /**
     * Parse HTML served from $url. Usable directly by PHP callers.
     */
    public function parse(string $html, string $url): ParsedHtml
    {
        $warnings = [];

        if (trim($html) === '') {
            return $this->empty($url, ['Document body is empty.']);
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
        } catch (Throwable $exception) {
            throw new ToolError(ErrorCode::ParseFailed, 'HTML could not be parsed.', ['url' => $url], null, $exception);
        }

        $base = $this->baseUrl($document, $url);

        // Head-level facts are read before any node is removed.
        $metadata = $this->metadata($document);
        $openGraph = array_filter($metadata, static fn (string $key): bool => str_starts_with($key, 'og:'), ARRAY_FILTER_USE_KEY);
        $jsonLd = $this->jsonLd($document, $warnings);
        $canonical = $this->canonical($document, $base, $metadata, $warnings);
        $language = trim((string) $document->documentElement?->getAttribute('lang')) ?: null;
        $author = $this->author($metadata, $jsonLd);
        $dates = $this->dateCandidates($metadata, $jsonLd, $warnings);

        // Pick the main container, then strip navigation and scripts from it.
        [$main, $selector] = $this->mainContent($document, $warnings);
        $this->removeNoise($document);

        $dates = [...$dates, ...$this->timeElementCandidates($main, $warnings)];
        usort($dates, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        $headings = $this->headings($main);
        $links = $this->links($main, $base);
        $text = $this->normalizeWhitespace($main->textContent);
        $markdown = $this->markdown($main->innerHTML);
        $title = $this->title($document, $openGraph, $headings, $warnings);

        $quality = [
            'text_characters' => mb_strlen($text),
            'markdown_characters' => mb_strlen($markdown),
            'link_count' => count($links),
            'heading_count' => count($headings),
            'has_title' => $title !== null,
            'has_canonical' => $canonical !== null,
            'main_content_selector' => $selector,
        ];

        if ($text === '') {
            $warnings[] = 'No text content in main container.';
        }

        return new ParsedHtml(
            title: $title,
            canonicalUrl: $canonical,
            language: $language,
            author: $author,
            dateCandidates: $dates,
            metadata: $metadata,
            openGraph: $openGraph,
            jsonLd: $jsonLd,
            headings: $headings,
            links: $links,
            markdown: $markdown,
            text: $text,
            mainContentSelector: $selector,
            quality: $quality,
            warnings: $warnings,
        );
    }

    /**
     * Result for a body with nothing in it: a valid parse with zero content,
     * so quality validation (not this tool) decides it is a failure.
     *
     * @param  list<string>  $warnings
     */
    private function empty(string $url, array $warnings): ParsedHtml
    {
        return new ParsedHtml(null, null, null, null, [], [], [], [], [], [], '', '', 'body', [
            'text_characters' => 0, 'markdown_characters' => 0, 'link_count' => 0, 'heading_count' => 0,
            'has_title' => false, 'has_canonical' => false, 'main_content_selector' => 'body',
        ], $warnings);
    }

    /**
     * <base href> wins over the served URL for resolving relative links.
     */
    private function baseUrl(HTMLDocument $document, string $url): string
    {
        $href = trim((string) $document->querySelector('base[href]')?->getAttribute('href'));

        if ($href === '') {
            return $url;
        }

        try {
            return UrlNormalizer::resolve($url, $href);
        } catch (InvalidArgumentException) {
            return $url;
        }
    }

    /**
     * All <meta name|property> pairs, lower-cased keys, first occurrence wins.
     *
     * @return array<string, string>
     */
    private function metadata(HTMLDocument $document): array
    {
        $metadata = [];

        foreach ($document->querySelectorAll('meta[name], meta[property]') as $meta) {
            $key = strtolower(trim((string) ($meta->getAttribute('property') ?: $meta->getAttribute('name'))));
            $value = trim((string) $meta->getAttribute('content'));

            if ($key !== '' && $value !== '' && ! isset($metadata[$key])) {
                $metadata[$key] = $value;
            }
        }

        ksort($metadata);

        return $metadata;
    }

    /**
     * Decoded JSON-LD blocks; invalid ones become warnings, not failures.
     *
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    private function jsonLd(HTMLDocument $document, array &$warnings): array
    {
        $blocks = [];

        foreach ($document->querySelectorAll('script[type="application/ld+json"]') as $index => $script) {
            try {
                $decoded = json_decode(trim($script->textContent), true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $warnings[] = "JSON-LD block {$index} is not valid JSON.";

                continue;
            }

            // A block may hold one object, a list, or a @graph; flatten to objects.
            $objects = is_array($decoded) && array_is_list($decoded) ? $decoded : [$decoded];

            foreach ($objects as $object) {
                if (! is_array($object)) {
                    continue;
                }

                if (isset($object['@graph']) && is_array($object['@graph'])) {
                    foreach ($object['@graph'] as $node) {
                        if (is_array($node)) {
                            $blocks[] = $node;
                        }
                    }

                    continue;
                }

                $blocks[] = $object;
            }
        }

        return $blocks;
    }

    /**
     * @param  array<string, string>  $metadata
     * @param  list<string>  $warnings
     */
    private function canonical(HTMLDocument $document, string $base, array $metadata, array &$warnings): ?string
    {
        $candidates = [
            trim((string) $document->querySelector('link[rel~="canonical"][href]')?->getAttribute('href')),
            $metadata['og:url'] ?? '',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }

            try {
                return UrlNormalizer::resolve($base, $candidate);
            } catch (InvalidArgumentException) {
                $warnings[] = "Unusable canonical URL: {$candidate}";
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $metadata
     * @param  list<array<string, mixed>>  $jsonLd
     */
    private function author(array $metadata, array $jsonLd): ?string
    {
        if (isset($metadata['author'])) {
            return $metadata['author'];
        }

        foreach ($jsonLd as $node) {
            $author = $node['author'] ?? null;

            if (is_array($author) && array_is_list($author)) {
                $author = $author[0] ?? null;
            }

            if (is_string($author) && trim($author) !== '') {
                return trim($author);
            }

            if (is_array($author) && is_string($author['name'] ?? null) && trim($author['name']) !== '') {
                return trim($author['name']);
            }
        }

        return null;
    }

    /**
     * Date candidates from <meta> and JSON-LD, each with its origin.
     *
     * @param  array<string, string>  $metadata
     * @param  list<array<string, mixed>>  $jsonLd
     * @param  list<string>  $warnings
     * @return list<array{kind: string, value: string|null, raw: string, source: string, confidence: float}>
     */
    private function dateCandidates(array $metadata, array $jsonLd, array &$warnings): array
    {
        $candidates = [];

        foreach ([['published', self::PUBLISHED_META], ['updated', self::UPDATED_META]] as [$kind, $keys]) {
            foreach ($keys as $key => $confidence) {
                if (isset($metadata[$key])) {
                    $candidates[] = $this->candidate($kind, $metadata[$key], "meta:{$key}", $confidence, $warnings);
                }
            }
        }

        foreach ($jsonLd as $index => $node) {
            foreach ([['published', 'datePublished'], ['updated', 'dateModified']] as [$kind, $property]) {
                if (is_string($node[$property] ?? null)) {
                    $candidates[] = $this->candidate($kind, $node[$property], "json-ld[{$index}].{$property}", 0.9, $warnings);
                }
            }
        }

        return $candidates;
    }

    /**
     * The first <time datetime> in the main content is a weak published hint.
     *
     * @param  list<string>  $warnings
     * @return list<array{kind: string, value: string|null, raw: string, source: string, confidence: float}>
     */
    private function timeElementCandidates(Element $main, array &$warnings): array
    {
        $time = $main->querySelector('time[datetime]');

        if ($time === null) {
            return [];
        }

        $raw = trim((string) $time->getAttribute('datetime'));

        return $raw === '' ? [] : [$this->candidate('published', $raw, 'time[datetime]', 0.6, $warnings)];
    }

    /**
     * Normalize one raw date to ISO 8601 UTC; unparseable values keep the raw
     * string, lose confidence and leave a warning.
     *
     * @param  list<string>  $warnings
     * @return array{kind: string, value: string|null, raw: string, source: string, confidence: float}
     */
    private function candidate(string $kind, string $raw, string $source, float $confidence, array &$warnings): array
    {
        try {
            $value = CarbonImmutable::parse($raw)->utc()->toIso8601ZuluString();
        } catch (InvalidFormatException) {
            $warnings[] = "Unparseable date from {$source}: {$raw}";

            return ['kind' => $kind, 'value' => null, 'raw' => $raw, 'source' => $source, 'confidence' => round($confidence / 2, 2)];
        }

        return ['kind' => $kind, 'value' => $value, 'raw' => $raw, 'source' => $source, 'confidence' => $confidence];
    }

    /**
     * @param  list<string>  $warnings
     * @return array{0: Element, 1: string}
     */
    private function mainContent(HTMLDocument $document, array &$warnings): array
    {
        foreach (self::MAIN_SELECTORS as $selector) {
            $element = $document->querySelector($selector);

            if ($element instanceof Element) {
                if ($selector === 'body') {
                    $warnings[] = 'No <main> or <article>; using <body>.';
                }

                return [$element, $selector];
            }
        }

        throw new ToolError(ErrorCode::ParseFailed, 'Document has no <body>.');
    }

    /**
     * Remove boilerplate everywhere. Site headers go too, but a header that
     * belongs to an <article> stays because it usually holds the title.
     */
    private function removeNoise(HTMLDocument $document): void
    {
        foreach (iterator_to_array($document->querySelectorAll(self::NOISE_SELECTOR), false) as $node) {
            $node->remove();
        }

        foreach (iterator_to_array($document->querySelectorAll('header'), false) as $header) {
            if ($header->closest('article') === null) {
                $header->remove();
            }
        }
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private function headings(Element $main): array
    {
        $headings = [];

        foreach ($main->querySelectorAll('h1, h2, h3, h4, h5, h6') as $heading) {
            $text = $this->normalizeWhitespace($heading->textContent);

            if ($text !== '') {
                $headings[] = ['level' => (int) substr($heading->nodeName, 1), 'text' => $text];
            }
        }

        return $headings;
    }

    /**
     * Absolute http(s) links in document order, deduplicated by URL.
     *
     * @return list<array{url: string, text: string}>
     */
    private function links(Element $main, string $base): array
    {
        $links = [];

        foreach ($main->querySelectorAll('a[href]') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            try {
                $url = UrlNormalizer::resolve($base, $href);
            } catch (InvalidArgumentException) {
                continue;
            }

            if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
                continue;
            }

            if (! isset($links[$url])) {
                $links[$url] = ['url' => $url, 'text' => mb_substr($this->normalizeWhitespace($anchor->textContent), 0, 200)];
            }
        }

        return array_values($links);
    }

    /**
     * @param  array<string, string>  $openGraph
     * @param  list<array{level: int, text: string}>  $headings
     * @param  list<string>  $warnings
     */
    private function title(HTMLDocument $document, array $openGraph, array $headings, array &$warnings): ?string
    {
        $title = $this->normalizeWhitespace((string) $document->querySelector('title')?->textContent);

        if ($title !== '') {
            return $title;
        }

        if (($openGraph['og:title'] ?? '') !== '') {
            return $openGraph['og:title'];
        }

        foreach ($headings as $heading) {
            if ($heading['level'] === 1) {
                return $heading['text'];
            }
        }

        $warnings[] = 'No title found.';

        return null;
    }

    /**
     * Convert the cleaned main content to Markdown. The converter is the only
     * third-party dependency here and is wrapped so it can be swapped by
     * bumping the parser version.
     */
    private function markdown(string $html): string
    {
        $converter = new HtmlConverter([
            'strip_tags' => true,
            'header_style' => 'atx',
            'hard_break' => false,
            'remove_nodes' => 'script style',
            'suppress_errors' => true,
        ]);

        $markdown = $converter->convert($html);

        // Collapse runs of blank lines so equal content yields equal bytes.
        $markdown = preg_replace('/[ \t]+\n/', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace("/\n{3,}/", "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}

<?php

namespace App\Acquisition\Tools\Discovery;

use App\Acquisition\Domain\Enums\ErrorCode;
use App\Acquisition\Domain\Identity\UrlNormalizer;
use App\Acquisition\Domain\Models\AcquisitionRun;
use App\Acquisition\Tools\ArrayResult;
use App\Acquisition\Tools\Html\ParsedHtml;
use App\Acquisition\Tools\Html\ParseHtmlTool;
use App\Acquisition\Tools\Http\FetchRequest;
use App\Acquisition\Tools\Http\FetchResult;
use App\Acquisition\Tools\Http\PolicyFetcher;
use App\Acquisition\Tools\Http\RobotsPolicy;
use App\Acquisition\Tools\Storage\StorageTool;
use App\Acquisition\Tools\Tool;
use App\Acquisition\Tools\ToolContext;
use App\Acquisition\Tools\ToolError;
use App\Acquisition\Tools\ToolRequest;
use App\Acquisition\Tools\ToolResult;
use App\Acquisition\Tools\Xml\ParseXmlTool;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use InvalidArgumentException;
use SplPriorityQueue;

/**
 * Web Discovery Tool (plan §7.1): a bounded, deterministic exploration of
 * one official site that surfaces stable acquisition routes: feeds,
 * sitemaps, index pages, pagination and PDF links. It never leaves the
 * allowed hosts, respects robots.txt and the rate limit, deduplicates by
 * normalized URL, and stops at the first exhausted budget. The Agent
 * judges what it returns; this tool only collects.
 */
final class DiscoverWebTool implements Tool
{
    /**
     * Paths worth one probe each when nothing advertises them.
     */
    private const WELL_KNOWN = ['/sitemap.xml', '/sitemap_index.xml', '/feed', '/feed.xml', '/rss.xml', '/rss', '/atom.xml'];

    // Well-known probes are a handful of cheap fetches that find the most
    // stable routes, so they go before hint and archive links: on real sites
    // (DARPA, NEDO) those links exhausted the URL budget before any probe ran.
    private const PRIORITY = ['seed' => 100, 'sitemap' => 90, 'feed' => 90, 'probe' => 85, 'hint' => 80, 'archive' => 60, 'pagination' => 50, 'link' => 10];

    private const XML_TYPES = ['text/xml', 'application/xml', 'application/rss+xml', 'application/atom+xml'];

    public function __construct(
        private PolicyFetcher $fetcher,
        private RobotsPolicy $robots,
        private ParseHtmlTool $html,
        private ParseXmlTool $xml,
        private StorageTool $storage,
    ) {}

    public function name(): string
    {
        return 'discover_web';
    }

    public function parseRequest(array $payload): ToolRequest
    {
        return DiscoverWebRequest::fromArray($payload);
    }

    public function run(ToolRequest $request, ToolContext $context): ToolResult
    {
        if (! $request instanceof DiscoverWebRequest) {
            throw new ToolError(ErrorCode::InvalidInput, 'discover_web expects a DiscoverWebRequest.');
        }

        $run = AcquisitionRun::query()->findOrFail($context->runId);
        $startedAt = CarbonImmutable::now('UTC');
        $deadline = microtime(true) + $request->maxSeconds;

        $frontier = new SplPriorityQueue;
        $sequence = 0;
        $seen = [];
        $resources = [];
        $edges = [];
        $failures = [];
        $candidates = ['feeds' => [], 'sitemaps' => [], 'indexes' => [], 'pagination' => [], 'pdfs' => [], 'external_hosts' => []];
        $fetched = 0;
        $depthReached = 0;

        $enqueue = function (string $url, int $depth, string $relation, ?string $from) use (&$frontier, &$sequence, &$seen, $request): void {
            try {
                $normalized = UrlNormalizer::normalize($url);
                $host = UrlNormalizer::host($url);
            } catch (InvalidArgumentException) {
                return;
            }

            if (isset($seen[$normalized]) || ! in_array($host, $request->allowedHosts, true)) {
                return;
            }

            // Verification fetches of feeds and sitemaps may exceed the depth
            // limit: they are the routes we are looking for.
            if ($depth > $request->maxDepth && ! in_array($relation, ['feed', 'sitemap'], true)) {
                return;
            }

            $seen[$normalized] = true;
            $frontier->insert([$url, $depth, $relation, $from], [self::PRIORITY[$relation] ?? 0, -$sequence++]);
        };

        $enqueue($request->seedUrl, 0, 'seed', null);

        // robots.txt Sitemap: directives and well-known locations are the
        // cheapest stable routes, so they are queued before anything is fetched.
        foreach ($this->robots->sitemaps($request->seedUrl, $request->allowedHosts, $context) as $sitemap) {
            $enqueue($sitemap, 1, 'sitemap', 'robots.txt');
        }

        $origin = self::origin($request->seedUrl);

        foreach (self::WELL_KNOWN as $path) {
            $enqueue($origin.$path, 1, 'probe', 'well-known');
        }

        $stoppedReason = 'frontier_empty';

        while (! $frontier->isEmpty()) {
            if ($fetched >= $request->maxUrls) {
                $stoppedReason = 'budget_exhausted';
                break;
            }

            if (microtime(true) >= $deadline) {
                $stoppedReason = 'time_exhausted';
                break;
            }

            /** @var array{0: string, 1: int, 2: string, 3: string|null} $item */
            $item = $frontier->extract();
            [$url, $depth, $relation, $from] = $item;
            $fetched++;
            $depthReached = max($depthReached, $depth);

            if ($from !== null) {
                $edges[] = ['from' => $from, 'to' => $url];
            }

            try {
                $result = $this->fetcher->fetch(
                    new FetchRequest($url, $request->allowedHosts, null, null, 15, 5 * 1024 * 1024, 5, 2),
                    $request->requestsPerMinute,
                    $context,
                );
            } catch (ToolError $error) {
                $failures[] = ['url' => $url, 'relation' => $relation, 'code' => $error->errorCode->value];
                $resources[] = ['url' => $url, 'relation' => $relation, 'depth' => $depth, 'status' => null, 'media_type' => null, 'error' => $error->errorCode->value];

                continue;
            }

            $mediaType = $result->declaredMediaType ?? $result->detectedMediaType;
            $resource = ['url' => $url, 'final_url' => $result->finalUrl, 'relation' => $relation, 'depth' => $depth, 'status' => $result->status, 'media_type' => $mediaType, 'bytes' => strlen($result->body)];

            if ($mediaType === 'text/html' || $mediaType === 'application/xhtml+xml') {
                $this->exploreHtml($result, $depth, $request, $resource, $candidates, $enqueue);
            } elseif (in_array($mediaType, self::XML_TYPES, true)) {
                $this->exploreXml($result, $url, $depth, $relation, $resource, $candidates, $enqueue);
            } elseif ($mediaType === 'application/pdf') {
                $candidates['pdfs'][] = $result->finalUrl;
            }

            $resources[] = $resource;
        }

        $candidates['pdfs'] = array_values(array_unique($candidates['pdfs']));
        ksort($candidates['external_hosts']);

        // Persist what was seen so later runs can tell new routes from old ones.
        $this->storage->storeDiscoveryResult($run, $run->source, array_map(
            static fn (array $resource): array => ['url' => $resource['url'], 'relation' => $resource['relation'], 'media_type' => $resource['media_type'] ?? null, 'depth' => $resource['depth']],
            $resources,
        ), $startedAt);

        return new ArrayResult([
            'seed_url' => $request->seedUrl,
            'allowed_hosts' => $request->allowedHosts,
            'candidates' => $candidates,
            'resources' => $resources,
            'edges' => $edges,
            'failures' => $failures,
            'budget' => [
                'urls_fetched' => $fetched,
                'max_urls' => $request->maxUrls,
                'depth_reached' => $depthReached,
                'max_depth' => $request->maxDepth,
                'seconds_used' => round(microtime(true) - ($deadline - $request->maxSeconds), 1),
                'max_seconds' => $request->maxSeconds,
                'frontier_remaining' => $frontier->count(),
            ],
            'stopped_reason' => $stoppedReason,
        ]);
    }

    /**
     * Classify an HTML page's links and queue the promising ones.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $candidates
     */
    private function exploreHtml(FetchResult $result, int $depth, DiscoverWebRequest $request, array &$resource, array &$candidates, callable $enqueue): void
    {
        try {
            $parsed = $this->html->parse($result->body, $result->finalUrl);
        } catch (ToolError $error) {
            $resource['error'] = $error->errorCode->value;

            return;
        }

        $resource['title'] = $parsed->title;
        $resource['canonical_url'] = $parsed->canonicalUrl;
        $resource['json_ld_types'] = array_values(array_unique(array_filter(array_map(
            static fn (array $node): ?string => is_string($node['@type'] ?? null) ? $node['@type'] : null,
            $parsed->jsonLd,
        ))));

        foreach ($this->feedLinks($result->body, $result->finalUrl) as $feedUrl) {
            $enqueue($feedUrl, $depth + 1, 'feed', $result->finalUrl);
        }

        $sameHost = 0;

        // Navigation links are exactly the routes discovery is after, so
        // links come from the whole document, not from the main content.
        foreach ($this->allLinks($result->body, $result->finalUrl) as $link) {
            $host = UrlNormalizer::host($link['url']);

            if (! in_array($host, $request->allowedHosts, true)) {
                if ($host !== null) {
                    $candidates['external_hosts'][$host] = ($candidates['external_hosts'][$host] ?? 0) + 1;
                }

                continue;
            }

            $sameHost++;
            $path = strtolower(parse_url($link['url'], PHP_URL_PATH) ?: '/');
            $query = strtolower(parse_url($link['url'], PHP_URL_QUERY) ?: '');
            $text = strtolower(trim($link['text']));

            if (str_ends_with($path, '.pdf')) {
                $candidates['pdfs'][] = $link['url'];

                continue;
            }

            if (preg_match('#/(sitemap[^/]*\.xml|feed|rss|atom)(\.xml)?/?$#', $path) === 1) {
                $enqueue($link['url'], $depth + 1, str_contains($path, 'sitemap') ? 'sitemap' : 'feed', $result->finalUrl);

                continue;
            }

            $isPagination = preg_match('#(^|[?&])(page|p|pg|offset|start)=\d+#', $query) === 1
                || preg_match('#/page/\d+#', $path) === 1
                || in_array($text, ['next', 'next page', 'older', 'more', '»', '›', '次へ', '次のページ', '次ページ'], true);

            if ($isPagination) {
                $candidates['pagination'][] = ['from' => $result->finalUrl, 'to' => $link['url']];
                $enqueue($link['url'], $depth + 1, 'pagination', $result->finalUrl);

                continue;
            }

            $matchesHint = array_filter($request->hints, static fn (string $hint): bool => $hint !== '' && str_contains(strtolower($link['url']), strtolower($hint)));

            if ($matchesHint !== []) {
                $enqueue($link['url'], $depth + 1, 'hint', $result->finalUrl);

                continue;
            }

            if (preg_match('#/(news|press|media|releases?|announcements?|archive|events?|publications?|reports?|programs?|projects?|\d{4})(/|$)#', $path) === 1) {
                $enqueue($link['url'], $depth + 1, 'archive', $result->finalUrl);

                continue;
            }

            $enqueue($link['url'], $depth + 1, 'link', $result->finalUrl);
        }

        // Navigation inflates whole-document counts on every page; only links
        // inside the main content say whether a page is a listing.
        $contentLinks = count(array_filter($parsed->links, fn (array $link): bool => in_array(UrlNormalizer::host($link['url']), $request->allowedHosts, true)));
        $resource['same_host_links'] = $sameHost;
        $resource['content_links'] = $contentLinks;

        // A page whose content links to many same-host documents is an index worth monitoring.
        if ($contentLinks >= 10) {
            $candidates['indexes'][] = ['url' => $result->finalUrl, 'title' => $parsed->title, 'same_host_links' => $contentLinks, 'depth' => $depth];
        }
    }

    /**
     * Record a feed or sitemap and queue sitemap children.
     *
     * @param  array<string, mixed>  $resource
     * @param  array<string, mixed>  $candidates
     */
    private function exploreXml(FetchResult $result, string $url, int $depth, string $relation, array &$resource, array &$candidates, callable $enqueue): void
    {
        try {
            $parsed = $this->xml->parse($result->body, $result->finalUrl);
        } catch (ToolError $error) {
            $resource['error'] = $error->errorCode->value;

            return;
        }

        $resource['kind'] = $parsed->kind;
        $resource['entry_count'] = count($parsed->entries);
        $resource['child_count'] = count($parsed->children);

        // A probe or plain link that turned out to be a feed or sitemap is
        // recorded as what it is; discovered_via keeps how we got there.
        if (in_array($relation, ['probe', 'link', 'archive', 'hint'], true) && $parsed->kind !== 'xml') {
            $resource['relation'] = str_starts_with($parsed->kind, 'sitemap') ? 'sitemap' : 'feed';
        }

        $sample = array_slice(array_values(array_filter(array_column($parsed->entries, 'url'))), 0, 5);

        if ($parsed->kind === 'rss' || $parsed->kind === 'atom') {
            $candidates['feeds'][] = ['url' => $result->finalUrl, 'kind' => $parsed->kind, 'title' => $parsed->feed['title'], 'entry_count' => count($parsed->entries), 'latest' => $parsed->entries[0]['published'] ?? $parsed->entries[0]['updated'] ?? null, 'sample_urls' => $sample, 'next' => $parsed->pagination['next'], 'discovered_via' => $relation];
        } elseif ($parsed->kind === 'sitemap') {
            $candidates['sitemaps'][] = ['url' => $result->finalUrl, 'kind' => 'sitemap', 'entry_count' => count($parsed->entries), 'sample_urls' => $sample, 'discovered_via' => $relation];

            foreach ($parsed->entries as $entry) {
                if ($entry['url'] !== null && str_ends_with(strtolower($entry['url']), '.pdf')) {
                    $candidates['pdfs'][] = $entry['url'];
                }
            }
        } elseif ($parsed->kind === 'sitemap_index') {
            $candidates['sitemaps'][] = ['url' => $result->finalUrl, 'kind' => 'sitemap_index', 'child_count' => count($parsed->children), 'children' => array_column($parsed->children, 'url'), 'discovered_via' => $relation];

            foreach ($parsed->children as $child) {
                $enqueue($child['url'], $depth + 1, 'sitemap', $url);
            }
        }
    }

    /**
     * Every absolute http(s) link in the document, deduplicated, in order.
     *
     * @return list<array{url: string, text: string}>
     */
    private function allLinks(string $html, string $baseUrl): array
    {
        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS);
        } catch (\Throwable) {
            return [];
        }

        $base = trim((string) $document->querySelector('base[href]')?->getAttribute('href'));

        try {
            $baseUrl = $base === '' ? $baseUrl : UrlNormalizer::resolve($baseUrl, $base);
        } catch (InvalidArgumentException) {
            // Keep the served URL as base.
        }

        $links = [];

        foreach ($document->querySelectorAll('a[href]') as $anchor) {
            $href = trim((string) $anchor->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#')) {
                continue;
            }

            try {
                $url = UrlNormalizer::resolve($baseUrl, $href);
            } catch (InvalidArgumentException) {
                continue;
            }

            if ((str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) && ! isset($links[$url])) {
                $links[$url] = ['url' => $url, 'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $anchor->textContent)), 0, 200)];
            }
        }

        return array_values($links);
    }

    /**
     * <link rel="alternate"> feed advertisements. ParsedHtml does not carry
     * link elements, so this is the one place raw HTML is inspected.
     *
     * @return list<string>
     */
    private function feedLinks(string $html, string $baseUrl): array
    {
        $feeds = [];

        if (preg_match_all('#<link\b[^>]*>#i', $html, $tags) === 0) {
            return $feeds;
        }

        foreach ($tags[0] as $tag) {
            if (preg_match('/\btype\s*=\s*["\']?(application\/(?:rss|atom)\+xml)/i', $tag) !== 1 || preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href) !== 1) {
                continue;
            }

            try {
                $feeds[] = UrlNormalizer::resolve($baseUrl, html_entity_decode($href[1]));
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return array_values(array_unique($feeds));
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);

        return ($parts['scheme'] ?? 'https').'://'.strtolower($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}

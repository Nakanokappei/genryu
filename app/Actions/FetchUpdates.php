<?php

namespace App\Actions;

use App\Models\Source;
use App\Models\UpdateEntry;
use Carbon\CarbonImmutable;
use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * 更新リストを取得 (UI: "Fetch updates", stage 2.1 of docs/HANDOVER.md).
 *
 * Deterministic. A source with an HTML list configuration is read from
 * its HTML list, page by page. Otherwise a feed is looked for in a fixed
 * order: the source URL itself is RSS / Atom; the HTML page advertises
 * one with <link rel="alternate">; a well-known path answers with one.
 * Without either there is nothing to read and the user is told so.
 */
class FetchUpdates
{
    public const USER_AGENT = 'TechnologyWatch/0.2 (+https://technologywatch.test)';

    /**
     * Paths sites commonly serve a feed at without advertising it (DARPA
     * has /rss.xml but no <link rel="alternate">). Tried in this order.
     */
    public const WELL_KNOWN = ['/rss.xml', '/feed', '/feed.xml', '/atom.xml', '/rss'];

    /**
     * Keys of the HTML list configuration (UI: "HTML list settings"):
     * CSS selectors for the item, the title link and the date inside an
     * item, the next-page link, and how many pages one fetch may read.
     */
    public const LIST_CONFIG_KEYS = ['item', 'title', 'date', 'next', 'max_pages'];

    /**
     * @return array{feed_url: ?string, pages: int, added: int, existing: int}
     */
    public function __invoke(Source $source): array
    {
        $config = $source->list_config ?? [];

        if (($config['item'] ?? '') !== '') {
            return $this->fromHtmlList($source, $config);
        }

        return $this->fromFeed($source);
    }

    /**
     * @return array{feed_url: string, pages: int, added: int, existing: int}
     */
    private function fromFeed(Source $source): array
    {
        $body = $this->get($source->url)->body();

        if (self::looksLikeFeed($body)) {
            $feedUrl = $source->url;
        } elseif (($feedUrl = self::advertisedFeed($body, $source->url)) !== null) {
            $body = $this->get($feedUrl)->body();

            if (! self::looksLikeFeed($body)) {
                throw new RuntimeException(__('The advertised feed is not RSS or Atom.').' ('.$feedUrl.')');
            }
        } elseif (($probe = $this->probeWellKnown($source->url)) !== null) {
            [$feedUrl, $body] = $probe;
        } else {
            throw new RuntimeException(__('No RSS or Atom feed found. Fill in the HTML list settings to read this page.'));
        }

        $counts = $this->store($source, self::feedEntries($body));
        $source->update(['feed_url' => $feedUrl, 'fetched_at' => now()]);

        return ['feed_url' => $feedUrl, 'pages' => 1, ...$counts];
    }

    /**
     * Read the HTML list page by page. The next page is read only while a
     * next link exists, the page just read listed something new, and the
     * page budget is not used up: a routine fetch stops at the first page
     * that is already known, a first fetch stops at max_pages.
     *
     * @param  array<string, mixed>  $config
     * @return array{feed_url: null, pages: int, added: int, existing: int}
     */
    private function fromHtmlList(Source $source, array $config): array
    {
        $maxPages = max(1, (int) ($config['max_pages'] ?? 1));
        $url = $source->url;
        $pages = 0;
        $added = 0;
        $existing = 0;

        while ($url !== null && $pages < $maxPages) {
            $document = self::html($this->get($url)->body());
            $pages++;

            $counts = $this->store($source, self::listEntries($document, $config, $url));
            $added += $counts['added'];
            $existing += $counts['existing'];

            $next = ($config['next'] ?? '') !== '' ? $document->querySelector((string) $config['next']) : null;
            $url = $next instanceof Element && $counts['added'] > 0 ? self::withoutFragment(self::absolute(trim((string) $next->getAttribute('href')), $url)) : null;
        }

        if ($pages === 1 && $added + $existing === 0) {
            throw new RuntimeException(__('The HTML list settings matched nothing on this page.'));
        }

        $source->update(['feed_url' => null, 'fetched_at' => now()]);

        return ['feed_url' => null, 'pages' => $pages, 'added' => $added, 'existing' => $existing];
    }

    /**
     * @param  list<array{title: string, url: string, published_at: ?string}>  $entries
     * @return array{added: int, existing: int}
     */
    private function store(Source $source, array $entries): array
    {
        $added = 0;
        $existing = 0;

        foreach ($entries as $entry) {
            $created = UpdateEntry::query()->firstOrCreate(
                ['source_id' => $source->id, 'url' => $entry['url']],
                ['title' => $entry['title'], 'published_at' => $entry['published_at']],
            );
            $created->wasRecentlyCreated ? $added++ : $existing++;
        }

        return ['added' => $added, 'existing' => $existing];
    }

    private function get(string $url): Response
    {
        return Http::withUserAgent(self::USER_AGENT)->timeout(20)->get($url)->throw();
    }

    /**
     * The first well-known path at the site's origin that answers with a feed.
     *
     * @return array{0: string, 1: string}|null the feed URL and its body
     */
    private function probeWellKnown(string $url): ?array
    {
        $origin = self::absolute('/', $url);

        foreach (self::WELL_KNOWN as $path) {
            $candidate = rtrim($origin, '/').$path;
            $response = Http::withUserAgent(self::USER_AGENT)->timeout(20)->get($candidate);

            if ($response->successful() && self::looksLikeFeed($response->body())) {
                return [$candidate, $response->body()];
            }
        }

        return null;
    }

    private static function looksLikeFeed(string $body): bool
    {
        $head = ltrim(substr($body, 0, 2048));

        return preg_match('/<(rss|feed|rdf:RDF)[\s>]/i', $head) === 1;
    }

    /**
     * The first <link rel="alternate" type="application/rss+xml|atom+xml"> of an HTML page.
     */
    private static function advertisedFeed(string $html, string $baseUrl): ?string
    {
        if (preg_match_all('#<link\b[^>]*>#i', $html, $tags) === 0) {
            return null;
        }

        foreach ($tags[0] as $tag) {
            if (preg_match('/\btype\s*=\s*["\']?application\/(rss|atom)\+xml/i', $tag) === 1 && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href) === 1) {
                return self::absolute(html_entity_decode($href[1]), $baseUrl);
            }
        }

        return null;
    }

    private static function html(string $body): HTMLDocument
    {
        return HTMLDocument::createFromString($body, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');
    }

    /**
     * Items of an HTML list page per the configuration. An item without a
     * title link (a header row, say) is skipped.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    private static function listEntries(HTMLDocument $document, array $config, string $pageUrl): array
    {
        $entries = [];

        foreach ($document->querySelectorAll((string) $config['item']) as $item) {
            $link = ($config['title'] ?? '') !== '' ? $item->querySelector((string) $config['title']) : $item;
            $href = $link instanceof Element ? trim((string) $link->getAttribute('href')) : '';
            $title = $link instanceof Element ? trim((string) preg_replace('/\s+/u', ' ', $link->textContent)) : '';

            if ($href === '' || $title === '') {
                continue;
            }

            $dateNode = ($config['date'] ?? '') !== '' ? $item->querySelector((string) $config['date']) : null;
            $rawDate = $dateNode instanceof Element ? (trim((string) $dateNode->getAttribute('datetime')) ?: trim((string) $dateNode->textContent)) : '';

            $entries[] = ['title' => $title, 'url' => self::withoutFragment(self::absolute($href, $pageUrl)), 'published_at' => self::date($rawDate)];
        }

        return $entries;
    }

    /**
     * RSS 2.0 items or Atom entries as title / url / published_at.
     *
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    private static function feedEntries(string $xml): array
    {
        // LIBXML_NONET: never follow external references from a feed.
        $document = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($document === false) {
            throw new RuntimeException(__('The feed could not be parsed.'));
        }

        $entries = [];

        foreach ($document->channel->item ?? [] as $item) {
            $entries[] = ['title' => trim((string) $item->title), 'url' => trim((string) $item->link), 'published_at' => self::date((string) $item->pubDate)];
        }

        foreach ($document->entry ?? [] as $entry) {
            $url = '';

            foreach ($entry->link as $link) {
                if ((string) $link['rel'] === '' || (string) $link['rel'] === 'alternate') {
                    $url = (string) $link['href'];
                    break;
                }
            }

            $entries[] = ['title' => trim((string) $entry->title), 'url' => trim($url), 'published_at' => self::date((string) ($entry->published ?: $entry->updated))];
        }

        return array_values(array_filter($entries, static fn (array $entry): bool => $entry['url'] !== '' && $entry['title'] !== ''));
    }

    /**
     * A date as printed (RFC 2822, ISO 8601, or Japanese 2026年9月17日) to Y-m-d.
     */
    private static function date(string $raw): ?string
    {
        $raw = trim((string) preg_replace('/\s+/u', '', $raw));

        if ($raw === '') {
            return null;
        }

        $raw = (string) preg_replace('/^(\d{4})年(\d{1,2})月(\d{1,2})日/u', '$1-$2-$3', $raw);

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A fragment (#press_table) is browser-side only: it neither reaches the
     * server nor distinguishes two documents.
     */
    private static function withoutFragment(string $url): string
    {
        return explode('#', $url, 2)[0];
    }

    private static function absolute(string $href, string $baseUrl): string
    {
        if (preg_match('#^https?://#i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $origin = ($base['scheme'] ?? 'https').'://'.($base['host'] ?? '');

        if (str_starts_with($href, '/')) {
            return $origin.$href;
        }

        $path = $base['path'] ?? '/';

        return $origin.rtrim(dirname($path), '/').'/'.$href;
    }
}

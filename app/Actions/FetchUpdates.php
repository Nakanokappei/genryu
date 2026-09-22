<?php

namespace App\Actions;

use App\Exceptions\RobotsForbidden;
use App\Jobs\FetchDocument;
use App\Models\EditorialPolicy;
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
 * Deterministic. A source with a JSON list configuration is read from
 * that JSON file; one with an HTML list configuration from its HTML
 * list, page by page. Otherwise a feed is looked for in a fixed order:
 * the source URL itself is RSS / Atom; the HTML page advertises one with
 * <link rel="alternate">; a well-known path answers with one. Without
 * any of these there is nothing to read and the user is told so.
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
     * Keys of the JSON list configuration (UI: "JSON list settings"): the
     * URL of the JSON file the site draws its list from, the path to the
     * item array inside it (dot notation, empty for the root), the keys of
     * the title, the link and the date inside an item, and how many items
     * from the top one fetch may take (the list is expected newest first).
     */
    public const JSON_CONFIG_KEYS = ['url', 'items', 'title', 'link', 'date', 'max_items'];

    public const DEFAULT_MAX_ITEMS = 50;

    /** A JSON list must hold at least this many entries to be believed. */
    public const MINIMUM_JSON_ENTRIES = 3;

    /** The last HTML page of the source read during a fetch, for its favicon. */
    private ?string $pageHtml = null;

    public function __construct(private FetchFavicon $favicon) {}

    /**
     * @return array{feed_url: ?string, pages: int, added: int, existing: int}
     */
    public function __invoke(Source $source): array
    {
        $this->pageHtml = null;
        $json = $source->json_config ?? [];
        $config = $source->list_config ?? [];

        $result = match (true) {
            ($json['url'] ?? '') !== '' => $this->fromJsonList($source, $json),
            ($config['item'] ?? '') !== '' => $this->fromHtmlList($source, $config),
            default => $this->fromFeed($source),
        };

        // A source still without its icon gets it while we are at the site anyway.
        ($this->favicon)($source, $this->pageHtml);

        return $result;
    }

    /**
     * Read the JSON file the site draws its list from, newest first, up
     * to max_items entries.
     *
     * @param  array<string, mixed>  $config
     * @return array{feed_url: null, pages: int, added: int, existing: int}
     */
    private function fromJsonList(Source $source, array $config): array
    {
        $entries = self::jsonEntries($this->get((string) $config['url'])->body(), $config, $source->url);

        if ($entries === []) {
            throw new RuntimeException(__('The JSON list settings matched nothing.'));
        }

        $counts = $this->store($source, array_slice($entries, 0, max(1, (int) ($config['max_items'] ?? self::DEFAULT_MAX_ITEMS))));
        $source->update(['feed_url' => null, 'fetched_at' => now()]);

        return ['feed_url' => null, 'pages' => 1, ...$counts];
    }

    /**
     * The entries of a JSON list per the configuration: the item array at
     * the configured path, each item's title / link / date by key (dot
     * notation reaches into nested objects). Items without a title or a
     * link are skipped; links resolve against the source page.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function jsonEntries(string $json, array $config, string $baseUrl): array
    {
        $data = json_decode($json, true);
        $items = ($config['items'] ?? '') === '' ? $data : data_get($data, (string) $config['items']);

        if (! is_array($items)) {
            return [];
        }

        $entries = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = trim((string) preg_replace('/\s+/u', ' ', (string) data_get($item, (string) ($config['title'] ?? 'title'))));
            $href = trim((string) data_get($item, (string) ($config['link'] ?? 'url')));

            if ($title === '' || $href === '') {
                continue;
            }

            $rawDate = ($config['date'] ?? '') !== '' ? (string) data_get($item, (string) $config['date']) : '';
            $entries[] = ['title' => $title, 'url' => self::withoutFragment(self::absolute($href, $baseUrl)), 'published_at' => self::date($rawDate)];
        }

        return $entries;
    }

    /**
     * Find the JSON list a page draws its entries from, deterministically:
     * every ".json" the HTML refers to is fetched (a handful at most) and
     * searched for an array of at least MINIMUM_JSON_ENTRIES objects that
     * carry a title-like and a link-like key. The first such array wins.
     *
     * @return array{config: array<string, mixed>, entries: list<array{title: string, url: string, published_at: ?string}>}|null
     */
    public function discoverJsonList(string $html, string $pageUrl): ?array
    {
        preg_match_all('#["\'=]([^"\'\s<>]+\.json(?:\?[^"\'\s<>]*)?)["\']#i', $html, $matches);
        $candidates = array_slice(array_unique(array_map(fn (string $href): string => self::absolute(html_entity_decode($href), $pageUrl), $matches[1])), 0, 5);

        foreach ($candidates as $candidate) {
            try {
                $body = $this->get($candidate)->body();
            } catch (\Throwable) {
                // A reference that cannot be fetched (or that robots.txt forbids) is simply not the list.
                continue;
            }

            $found = self::findItemArray(json_decode($body, true), '');

            if ($found === null) {
                continue;
            }

            $config = ['url' => $candidate, ...$found, 'max_items' => self::DEFAULT_MAX_ITEMS];
            $entries = self::jsonEntries($body, $config, $pageUrl);

            if (count($entries) >= self::MINIMUM_JSON_ENTRIES) {
                return ['config' => $config, 'entries' => $entries];
            }
        }

        return null;
    }

    /**
     * The first array of objects with a title-like and a link-like key,
     * searched breadth-first a few levels deep, with the keys it uses.
     *
     * @return array{items: string, title: string, link: string, date: string}|null
     */
    private static function findItemArray(mixed $data, string $path, int $depth = 0): ?array
    {
        if (! is_array($data) || $depth > 3) {
            return null;
        }

        $objects = array_values(array_filter($data, 'is_array'));

        if (array_is_list($data) && count($objects) >= self::MINIMUM_JSON_ENTRIES) {
            $keys = array_keys($objects[0]);
            $title = self::keyLike($keys, '/title|headline|subject/i');
            $link = self::keyLike($keys, '/^(url|link|href|path|permalink)$/i') ?? self::keyLike($keys, '/url|link|href/i');

            if ($title !== null && $link !== null) {
                return ['items' => $path, 'title' => $title, 'link' => $link, 'date' => self::keyLike($keys, '/date|published|time/i') ?? ''];
            }
        }

        foreach ($data as $key => $value) {
            $found = self::findItemArray($value, ltrim($path.'.'.$key, '.'), $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  list<int|string>  $keys
     */
    private static function keyLike(array $keys, string $pattern): ?string
    {
        foreach ($keys as $key) {
            if (is_string($key) && preg_match($pattern, $key) === 1) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @return array{feed_url: string, pages: int, added: int, existing: int}
     */
    private function fromFeed(Source $source): array
    {
        $this->pageHtml = $this->page($source->url);
        [$feedUrl, $body] = $this->discoverFeed($source->url, $this->pageHtml)
            ?? throw new RuntimeException(__('No RSS or Atom feed found. Fill in the HTML list settings to read this page.'));

        $counts = $this->store($source, self::feedEntries($body));
        $source->update(['feed_url' => $feedUrl, 'fetched_at' => now()]);

        return ['feed_url' => $feedUrl, 'pages' => 1, ...$counts];
    }

    /**
     * The body served at a URL, as the crawler identifies itself.
     */
    public function page(string $url): string
    {
        return $this->get($url)->body();
    }

    /**
     * Find the feed for a page whose body was already fetched, in the fixed
     * order: the body is a feed; it advertises one; a well-known path has one.
     *
     * @return array{0: string, 1: string}|null the feed URL and its body
     */
    public function discoverFeed(string $url, string $body): ?array
    {
        if (self::looksLikeFeed($body)) {
            return [$url, $body];
        }

        if (($feedUrl = self::advertisedFeed($body, $url)) !== null) {
            $feed = $this->get($feedUrl)->body();

            if (! self::looksLikeFeed($feed)) {
                throw new RuntimeException(__('The advertised feed is not RSS or Atom.').' ('.$feedUrl.')');
            }

            return [$feedUrl, $feed];
        }

        return $this->probeWellKnown($url);
    }

    /**
     * What HTML list settings would list on a page, for verifying a proposal
     * before it is saved.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function previewList(string $html, array $config, string $url): array
    {
        return ($config['item'] ?? '') === '' ? [] : self::listEntries(self::html($html), $config, $url);
    }

    /**
     * The entries of a feed body, for judging a discovered feed before it is adopted.
     *
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function previewFeed(string $xml): array
    {
        return self::feedEntries($xml);
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
            $body = $this->get($url)->body();
            $this->pageHtml ??= $body;
            $document = self::html($body);
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
     * Keep the entries; each new one has its document fetched in the
     * background (stage 2.2) without anyone asking, unless its title has
     * an exclude keyword of the editorial policy: it is then listed as
     * 対象外 with the keyword, and nothing is fetched for it.
     *
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
                ['title' => $entry['title'], 'published_at' => $entry['published_at'], 'excluded_by' => EditorialPolicy::excludedBy($entry['title'])],
            );

            if ($created->wasRecentlyCreated) {
                $added++;

                if ($created->excluded_by === null) {
                    FetchDocument::queueFor($created);
                }
            } else {
                $existing++;
            }
        }

        return ['added' => $added, 'existing' => $existing];
    }

    /**
     * robots.txt is enforced for every request by the global HTTP middleware
     * (AppServiceProvider); a forbidden URL throws App\Exceptions\RobotsForbidden.
     */
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

            try {
                $response = Http::withUserAgent(self::USER_AGENT)->timeout(20)->get($candidate);
            } catch (RobotsForbidden) {
                // A probe robots.txt forbids is simply not a route.
                continue;
            }

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
            $titleNode = ($config['title'] ?? '') !== '' ? $item->querySelector((string) $config['title']) : $item;
            // The title element may be the link, hold the link (a heading with an
            // anchor) or sit inside it (an anchor wrapping a heading).
            $link = $titleNode instanceof Element ? self::anchorOf($titleNode) : null;
            $href = $link instanceof Element ? trim((string) $link->getAttribute('href')) : '';
            $title = $titleNode instanceof Element ? trim((string) preg_replace('/\s+/u', ' ', $titleNode->textContent)) : '';

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
     * The anchor an element stands for: itself, the first anchor inside it,
     * or the nearest anchor around it.
     */
    private static function anchorOf(Element $element): ?Element
    {
        if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
            return $element;
        }

        $inside = $element->querySelector('a[href]');

        return $inside instanceof Element ? $inside : $element->closest('a[href]');
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
     * A date as printed (RFC 2822, ISO 8601, or Japanese 2026年9月17日) to
     * Y-m-d. Shared with ReadDocument, which dates a document the same way.
     */
    public static function date(string $raw): ?string
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

    /**
     * Resolve a link against the page it was found on: absolute, protocol-
     * relative, root-relative, query-only (?page=2, as Drupal pagers emit)
     * or relative to the page's directory. Shared with ReadDocument, which
     * resolves the links and images inside a document the same way.
     */
    public static function absolute(string $href, string $baseUrl): string
    {
        // Anything with a scheme (https:, mailto:, tel:) is already absolute.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $origin = $scheme.'://'.($base['host'] ?? '').(isset($base['port']) ? ':'.$base['port'] : '');
        $path = $base['path'] ?? '/';

        return match (true) {
            str_starts_with($href, '//') => $scheme.':'.$href,
            str_starts_with($href, '/') => $origin.self::withoutDotSegments($href),
            str_starts_with($href, '?') => $origin.$path.$href,
            default => $origin.self::withoutDotSegments(rtrim(dirname($path), '/').'/'.$href),
        };
    }

    /**
     * Resolve "." and ".." in a path (../img/burner.png from /news/1 is
     * /img/burner.png), leaving any query string as it is.
     */
    private static function withoutDotSegments(string $path): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($segments);
            } elseif ($segment !== '.') {
                $segments[] = $segment;
            }
        }

        return '/'.ltrim(implode('/', $segments), '/').($query !== null ? '?'.$query : '');
    }
}

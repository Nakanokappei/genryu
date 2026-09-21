<?php

namespace App\Actions;

use App\Models\Source;
use App\Models\UpdateEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

/**
 * 更新リストを取得 (UI: "Fetch updates", stage 2.1 of docs/HANDOVER.md).
 *
 * Deterministic, in this order: the source URL itself is an RSS / Atom
 * feed; or it is an HTML page that advertises one with
 * <link rel="alternate">; otherwise there is no feed and reading the HTML
 * list needs a per-source configuration, which is not built yet.
 */
class FetchUpdates
{
    public const USER_AGENT = 'TechnologyWatch/0.2 (+https://technologywatch.test)';

    /**
     * @return array{feed_url: string, added: int, existing: int}
     */
    public function __invoke(Source $source): array
    {
        $response = $this->get($source->url);
        $body = $response->body();

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
            throw new RuntimeException(__('No RSS or Atom feed found. Reading an HTML list needs a configuration, which is not available yet.'));
        }

        $added = 0;
        $existing = 0;

        foreach (self::entries($body) as $entry) {
            $created = UpdateEntry::query()->firstOrCreate(
                ['source_id' => $source->id, 'url' => $entry['url']],
                ['title' => $entry['title'], 'published_at' => $entry['published_at']],
            );
            $created->wasRecentlyCreated ? $added++ : $existing++;
        }

        $source->update(['feed_url' => $feedUrl, 'fetched_at' => now()]);

        return ['feed_url' => $feedUrl, 'added' => $added, 'existing' => $existing];
    }

    /**
     * Paths sites commonly serve a feed at without advertising it (DARPA
     * has /rss.xml but no <link rel="alternate">). Tried in this order.
     */
    public const WELL_KNOWN = ['/rss.xml', '/feed', '/feed.xml', '/atom.xml', '/rss'];

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

    /**
     * RSS 2.0 items or Atom entries as title / url / published_at.
     *
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    private static function entries(string $xml): array
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

    private static function date(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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

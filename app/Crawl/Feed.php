<?php

namespace App\Crawl;

use App\Exceptions\RobotsForbidden;
use RuntimeException;
use SimpleXMLElement;

/**
 * An update list read from an RSS / Atom feed: finding it and reading its entries.
 */
class Feed
{
    /** Paths tried, in order, for a feed a page does not advertise. */
    public const WELL_KNOWN = ['/rss.xml', '/feed', '/feed.xml', '/atom.xml', '/rss'];

    /** The namespace of arXiv's own elements in its feeds (arxiv:announce_type). */
    private const ARXIV_NAMESPACE = 'http://arxiv.org/schemas/atom';

    /**
     * The feed for a fetched page: the page itself, the one it advertises, or a well-known path.
     *
     * @return array{0: string, 1: string}|null the feed URL and its body
     */
    public static function discover(string $url, string $body): ?array
    {
        // The page is a feed.
        if (self::looksLikeFeed($body)) {
            return [$url, $body];
        }

        // The page advertises one, which must be a feed.
        if (($feedUrl = self::advertisedFeed($body, $url)) !== null) {
            $feed = Crawler::get($feedUrl)->body();

            if (! self::looksLikeFeed($feed)) {
                throw new RuntimeException(__('The advertised feed is not RSS or Atom.').' ('.$feedUrl.')');
            }

            return [$feedUrl, $feed];
        }

        return self::probeWellKnown($url);
    }

    /**
     * RSS items or Atom entries with their summary as plain text; arXiv's
     * re-announcements (announce_type replace*) are dropped.
     *
     * @return list<array{title: string, url: string, published_at: ?string, summary: string}>
     */
    public static function entries(string $xml): array
    {
        // LIBXML_NONET: never follow external references from a feed.
        $document = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        // Not XML.
        if ($document === false) {
            throw new RuntimeException(__('The feed could not be parsed.'));
        }

        $entries = [];

        // RSS items, arXiv replacements skipped.
        foreach ($document->channel->item ?? [] as $item) {
            if (str_starts_with((string) $item->children(self::ARXIV_NAMESPACE)->announce_type, 'replace')) {
                continue;
            }

            $entries[] = ['title' => trim((string) $item->title), 'url' => trim((string) $item->link), 'published_at' => PublishedDate::parse((string) $item->pubDate), 'summary' => self::summaryText((string) $item->description)];
        }

        // Atom entries.
        foreach ($document->entry ?? [] as $entry) {
            $url = '';

            // The alternate (or unlabelled) link.
            foreach ($entry->link as $link) {
                if ((string) $link['rel'] === '' || (string) $link['rel'] === 'alternate') {
                    $url = (string) $link['href'];
                    break;
                }
            }

            $entries[] = ['title' => trim((string) $entry->title), 'url' => trim($url), 'published_at' => PublishedDate::parse((string) ($entry->published ?: $entry->updated)), 'summary' => self::summaryText((string) $entry->summary)];
        }

        return array_values(array_filter($entries, static fn (array $entry): bool => $entry['url'] !== '' && $entry['title'] !== ''));
    }

    /**
     * The first well-known path at the site's origin that answers with a feed.
     *
     * @return array{0: string, 1: string}|null the feed URL and its body
     */
    private static function probeWellKnown(string $url): ?array
    {
        $origin = Url::absolute('/', $url);

        // Each path in turn until one answers with a feed.
        foreach (self::WELL_KNOWN as $path) {
            $candidate = rtrim($origin, '/').$path;

            try {
                $response = Crawler::client()->get($candidate);
            } catch (RobotsForbidden) {
                // Forbidden by robots.txt: skip it.
                continue;
            }

            if ($response->successful() && self::looksLikeFeed($response->body())) {
                return [$candidate, $response->body()];
            }
        }

        return null;
    }

    /** Whether the body starts like RSS, Atom or RDF. */
    private static function looksLikeFeed(string $body): bool
    {
        $head = ltrim(substr($body, 0, 2048));

        return preg_match('/<(rss|feed|rdf:RDF)[\s>]/i', $head) === 1;
    }

    /** The URL of the first RSS or Atom <link> of an HTML page. */
    private static function advertisedFeed(string $html, string $baseUrl): ?string
    {
        // No <link> tags at all.
        if (preg_match_all('#<link\b[^>]*>#i', $html, $tags) === 0) {
            return null;
        }

        // The first with an RSS / Atom type and an href.
        foreach ($tags[0] as $tag) {
            if (preg_match('/\btype\s*=\s*["\']?application\/(rss|atom)\+xml/i', $tag) === 1 && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href) === 1) {
                return Url::absolute(html_entity_decode($href[1]), $baseUrl);
            }
        }

        return null;
    }

    /** A summary as plain text, without arXiv's leading "arXiv:… Announce Type: … Abstract:". */
    private static function summaryText(string $summary): string
    {
        $text = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/^arXiv:\S+\s+Announce Type:\s*\S+\s*(Abstract:\s*)?/i', '', $text));
    }
}

<?php

namespace App\Crawl;

use App\Exceptions\RobotsForbidden;
use RuntimeException;
use SimpleXMLElement;

/**
 * An update list read from an RSS / Atom feed: finding the feed of a page,
 * and reading its entries.
 */
class Feed
{
    /**
     * Paths sites commonly serve a feed at without advertising it (DARPA
     * has /rss.xml but no <link rel="alternate">). Tried in this order.
     */
    public const WELL_KNOWN = ['/rss.xml', '/feed', '/feed.xml', '/atom.xml', '/rss'];

    /** The namespace of arXiv's own elements in its feeds (arxiv:announce_type). */
    private const ARXIV_NAMESPACE = 'http://arxiv.org/schemas/atom';

    /**
     * Find the feed for a page whose body was already fetched, in the fixed
     * order: the body is a feed; it advertises one; a well-known path has one.
     *
     * @return array{0: string, 1: string}|null the feed URL and its body
     */
    public static function discover(string $url, string $body): ?array
    {
        if (self::looksLikeFeed($body)) {
            return [$url, $body];
        }

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
     * RSS 2.0 items or Atom entries as title / url / published_at, with
     * the summary the feed gives (description / summary) as plain text.
     * arXiv announces the replaced versions of papers it has listed before
     * (announce_type replace / replace-cross): only the first announcement
     * of a paper is an update.
     *
     * @return list<array{title: string, url: string, published_at: ?string, summary: string}>
     */
    public static function entries(string $xml): array
    {
        // LIBXML_NONET: never follow external references from a feed.
        $document = @simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($document === false) {
            throw new RuntimeException(__('The feed could not be parsed.'));
        }

        $entries = [];

        foreach ($document->channel->item ?? [] as $item) {
            if (str_starts_with((string) $item->children(self::ARXIV_NAMESPACE)->announce_type, 'replace')) {
                continue;
            }

            $entries[] = ['title' => trim((string) $item->title), 'url' => trim((string) $item->link), 'published_at' => PublishedDate::parse((string) $item->pubDate), 'summary' => self::summaryText((string) $item->description)];
        }

        foreach ($document->entry ?? [] as $entry) {
            $url = '';

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

        foreach (self::WELL_KNOWN as $path) {
            $candidate = rtrim($origin, '/').$path;

            try {
                $response = Crawler::client()->get($candidate);
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
                return Url::absolute(html_entity_decode($href[1]), $baseUrl);
            }
        }

        return null;
    }

    /**
     * A feed's summary as plain text: tags and entities gone, and arXiv's
     * leading "arXiv:2609.26800v1 Announce Type: new Abstract:" left out.
     */
    private static function summaryText(string $summary): string
    {
        $text = trim(html_entity_decode(strip_tags($summary), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return trim((string) preg_replace('/^arXiv:\S+\s+Announce Type:\s*\S+\s*(Abstract:\s*)?/i', '', $text));
    }
}

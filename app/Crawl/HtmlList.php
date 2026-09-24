<?php

namespace App\Crawl;

use Dom\Element;
use Dom\HTMLDocument;
use Illuminate\Support\Str;

/**
 * An update list read from an HTML page with the source's HTML list settings.
 */
class HtmlList
{
    /** Keys of the HTML list settings: selectors for item, title, date and next page, and the page limit. */
    public const SETTING_KEYS = ['item', 'title', 'date', 'next', 'max_pages'];

    /** Parse an HTML page. */
    public static function document(string $body): HTMLDocument
    {
        return HTMLDocument::createFromString($body, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');
    }

    /**
     * What the settings would list on a page, for verifying a proposal.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function preview(string $html, array $config, string $url): array
    {
        return ($config['item'] ?? '') === '' ? [] : self::entries(self::document($html), $config, $url);
    }

    /**
     * The entries of a list page; an item without a title link is skipped.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function entries(HTMLDocument $document, array $config, string $pageUrl): array
    {
        $entries = [];

        // Each item's title, link and date.
        foreach ($document->querySelectorAll((string) $config['item']) as $item) {
            $titleNode = ($config['title'] ?? '') !== '' ? $item->querySelector((string) $config['title']) : $item;
            // The title element may be, hold or sit inside the link.
            $link = $titleNode instanceof Element ? self::anchorOf($titleNode) : null;
            $href = $link instanceof Element ? trim((string) $link->getAttribute('href')) : '';
            $title = $titleNode instanceof Element ? Str::squish($titleNode->textContent) : '';

            // No link or no title: skip.
            if ($href === '' || $title === '') {
                continue;
            }

            $dateNode = ($config['date'] ?? '') !== '' ? $item->querySelector((string) $config['date']) : null;
            $rawDate = $dateNode instanceof Element ? (trim((string) $dateNode->getAttribute('datetime')) ?: trim((string) $dateNode->textContent)) : '';

            $entries[] = ['title' => $title, 'url' => Url::withoutFragment(Url::absolute($href, $pageUrl)), 'published_at' => PublishedDate::parse($rawDate)];
        }

        return $entries;
    }

    /**
     * The URL of the next page, when the settings and the page have a next-page link.
     *
     * @param  array<string, mixed>  $config
     */
    public static function nextPage(HTMLDocument $document, array $config, string $pageUrl): ?string
    {
        $next = ($config['next'] ?? '') !== '' ? $document->querySelector((string) $config['next']) : null;

        return $next instanceof Element ? Url::withoutFragment(Url::absolute(trim((string) $next->getAttribute('href')), $pageUrl)) : null;
    }

    /** The element itself if a link, else the first link inside it, else the nearest around it. */
    private static function anchorOf(Element $element): ?Element
    {
        // The element is the link.
        if (strtolower($element->tagName) === 'a' && $element->hasAttribute('href')) {
            return $element;
        }

        $inside = $element->querySelector('a[href]');

        return $inside instanceof Element ? $inside : $element->closest('a[href]');
    }
}

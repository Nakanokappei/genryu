<?php

namespace App\Crawl;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * An update list read from an HTML page with the HTML list settings
 * (UI: "HTML list settings"): CSS selectors for the item, the title link
 * and the date inside an item, and the next-page link.
 */
class HtmlList
{
    /**
     * Keys of the HTML list configuration: CSS selectors for the item, the
     * title link and the date inside an item, the next-page link, and how
     * many pages one fetch may read.
     */
    public const CONFIG_KEYS = ['item', 'title', 'date', 'next', 'max_pages'];

    public static function document(string $body): HTMLDocument
    {
        return HTMLDocument::createFromString($body, LIBXML_NOERROR | \Dom\HTML_NO_DEFAULT_NS, 'UTF-8');
    }

    /**
     * What HTML list settings would list on a page, for verifying a proposal
     * before it is saved.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function preview(string $html, array $config, string $url): array
    {
        return ($config['item'] ?? '') === '' ? [] : self::entries(self::document($html), $config, $url);
    }

    /**
     * Items of an HTML list page per the configuration. An item without a
     * title link (a header row, say) is skipped.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function entries(HTMLDocument $document, array $config, string $pageUrl): array
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

            $entries[] = ['title' => $title, 'url' => Url::withoutFragment(Url::absolute($href, $pageUrl)), 'published_at' => PublishedDate::parse($rawDate)];
        }

        return $entries;
    }

    /**
     * The URL of the next page of the list, when the settings name a
     * next-page link and the page has one.
     *
     * @param  array<string, mixed>  $config
     */
    public static function nextPage(HTMLDocument $document, array $config, string $pageUrl): ?string
    {
        $next = ($config['next'] ?? '') !== '' ? $document->querySelector((string) $config['next']) : null;

        return $next instanceof Element ? Url::withoutFragment(Url::absolute(trim((string) $next->getAttribute('href')), $pageUrl)) : null;
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
}

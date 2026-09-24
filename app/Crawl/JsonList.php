<?php

namespace App\Crawl;

use Illuminate\Support\Str;

/**
 * An update list read from the JSON file a site draws its list from
 * (UI: "JSON list settings"): finding that file on a page, and reading
 * its entries.
 */
class JsonList
{
    /**
     * Keys of the JSON list configuration: the URL of the JSON file, the
     * path to the item array inside it (dot notation, empty for the root),
     * the keys of the title, the link and the date inside an item, and how
     * many items from the top one fetch may take (the list is expected
     * newest first).
     */
    public const SETTING_KEYS = ['url', 'items', 'title', 'link', 'date', 'max_items'];

    public const DEFAULT_MAX_ITEMS = 50;

    /** A JSON list must hold at least this many entries to be believed. */
    public const MINIMUM_ENTRIES = 3;

    /**
     * The entries of a JSON list per the configuration: the item array at
     * the configured path, each item's title / link / date by key (dot
     * notation reaches into nested objects). Items without a title or a
     * link are skipped; links resolve against the source page.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function entries(string $json, array $config, string $baseUrl): array
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

            $title = Str::squish((string) data_get($item, (string) ($config['title'] ?? 'title')));
            $href = trim((string) data_get($item, (string) ($config['link'] ?? 'url')));

            if ($title === '' || $href === '') {
                continue;
            }

            $rawDate = ($config['date'] ?? '') !== '' ? (string) data_get($item, (string) $config['date']) : '';
            $entries[] = ['title' => $title, 'url' => Url::withoutFragment(Url::absolute($href, $baseUrl)), 'published_at' => PublishedDate::parse($rawDate)];
        }

        return $entries;
    }

    /**
     * Find the JSON list a page draws its entries from, deterministically:
     * every ".json" the HTML refers to is fetched (a handful at most) and
     * searched for an array of at least MINIMUM_ENTRIES objects that carry
     * a title-like and a link-like key. The first such array wins.
     *
     * @return array{config: array<string, mixed>, entries: list<array{title: string, url: string, published_at: ?string}>}|null
     */
    public static function discover(string $html, string $pageUrl): ?array
    {
        preg_match_all('#["\'=]([^"\'\s<>]+\.json(?:\?[^"\'\s<>]*)?)["\']#i', $html, $matches);
        $candidates = array_slice(array_unique(array_map(fn (string $href): string => Url::absolute(html_entity_decode($href), $pageUrl), $matches[1])), 0, 5);

        foreach ($candidates as $candidate) {
            try {
                $body = Crawler::get($candidate)->body();
            } catch (\Throwable) {
                // A reference that cannot be fetched (or that robots.txt forbids) is simply not the list.
                continue;
            }

            $found = self::findItemArray(json_decode($body, true), '');

            if ($found === null) {
                continue;
            }

            $config = ['url' => $candidate, ...$found, 'max_items' => self::DEFAULT_MAX_ITEMS];
            $entries = self::entries($body, $config, $pageUrl);

            if (count($entries) >= self::MINIMUM_ENTRIES) {
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

        if (array_is_list($data) && count($objects) >= self::MINIMUM_ENTRIES) {
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
}

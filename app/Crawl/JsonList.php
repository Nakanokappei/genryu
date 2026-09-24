<?php

namespace App\Crawl;

use Illuminate\Support\Str;

/**
 * An update list read from the JSON file a page draws its list from: finding
 * it and reading its entries with the source's JSON list settings.
 */
class JsonList
{
    /**
     * Keys of the JSON list settings: the file's URL, the dot path to the item
     * array (empty for the root), the title / link / date keys, and how many
     * items from the top are taken (newest first).
     */
    public const SETTING_KEYS = ['url', 'items', 'title', 'link', 'date', 'max_items'];

    public const DEFAULT_MAX_ITEMS = 50;

    /** A JSON list must hold at least this many entries to be believed. */
    public const MINIMUM_ENTRIES = 3;

    /**
     * The entries of a JSON list; items without a title or a link are skipped.
     *
     * @param  array<string, mixed>  $config
     * @return list<array{title: string, url: string, published_at: ?string}>
     */
    public static function entries(string $json, array $config, string $baseUrl): array
    {
        $data = json_decode($json, true);
        $items = ($config['items'] ?? '') === '' ? $data : data_get($data, (string) $config['items']);

        // No item array at the path.
        if (! is_array($items)) {
            return [];
        }

        $entries = [];

        // Each object's title, link and date.
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $title = Str::squish((string) data_get($item, (string) ($config['title'] ?? 'title')));
            $href = trim((string) data_get($item, (string) ($config['link'] ?? 'url')));

            // No title or no link: skip.
            if ($title === '' || $href === '') {
                continue;
            }

            $rawDate = ($config['date'] ?? '') !== '' ? (string) data_get($item, (string) $config['date']) : '';
            $entries[] = ['title' => $title, 'url' => Url::withoutFragment(Url::absolute($href, $baseUrl)), 'published_at' => PublishedDate::parse($rawDate)];
        }

        return $entries;
    }

    /**
     * The first of the page's .json references (at most five) that holds an
     * array of MINIMUM_ENTRIES objects with a title-like and a link-like key.
     *
     * @return array{config: array<string, mixed>, entries: list<array{title: string, url: string, published_at: ?string}>}|null
     */
    public static function discover(string $html, string $pageUrl): ?array
    {
        preg_match_all('#["\'=]([^"\'\s<>]+\.json(?:\?[^"\'\s<>]*)?)["\']#i', $html, $matches);
        $candidates = array_slice(array_unique(array_map(fn (string $href): string => Url::absolute(html_entity_decode($href), $pageUrl), $matches[1])), 0, 5);

        // Each candidate in turn until one lists enough entries.
        foreach ($candidates as $candidate) {
            try {
                $body = Crawler::get($candidate)->body();
            } catch (\Throwable) {
                // Unfetchable or forbidden: not the list.
                continue;
            }

            $found = self::findItemArray(json_decode($body, true), '');

            // No item array in it.
            if ($found === null) {
                continue;
            }

            $config = ['url' => $candidate, ...$found, 'max_items' => self::DEFAULT_MAX_ITEMS];
            $entries = self::entries($body, $config, $pageUrl);

            // Enough entries to believe.
            if (count($entries) >= self::MINIMUM_ENTRIES) {
                return ['config' => $config, 'entries' => $entries];
            }
        }

        return null;
    }

    /**
     * The first array of objects with a title-like and a link-like key, up to
     * four levels deep, with its path and keys.
     *
     * @return array{items: string, title: string, link: string, date: string}|null
     */
    private static function findItemArray(mixed $data, string $path, int $depth = 0): ?array
    {
        // Not an array, or too deep.
        if (! is_array($data) || $depth > 3) {
            return null;
        }

        $objects = array_values(array_filter($data, 'is_array'));

        // A list of enough objects: take it when its first has title and link keys.
        if (array_is_list($data) && count($objects) >= self::MINIMUM_ENTRIES) {
            $keys = array_keys($objects[0]);
            $title = self::keyLike($keys, '/title|headline|subject/i');
            $link = self::keyLike($keys, '/^(url|link|href|path|permalink)$/i') ?? self::keyLike($keys, '/url|link|href/i');

            if ($title !== null && $link !== null) {
                return ['items' => $path, 'title' => $title, 'link' => $link, 'date' => self::keyLike($keys, '/date|published|time/i') ?? ''];
            }
        }

        // Otherwise search each child.
        foreach ($data as $key => $value) {
            $found = self::findItemArray($value, ltrim($path.'.'.$key, '.'), $depth + 1);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The first string key matching the pattern.
     *
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

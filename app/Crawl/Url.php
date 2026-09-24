<?php

namespace App\Crawl;

/**
 * Links as the crawler finds them on a page, made into the URLs it keeps:
 * resolved against the page, and without the fragment.
 */
class Url
{
    /**
     * A fragment (#press_table) is browser-side only: it neither reaches the
     * server nor distinguishes two documents.
     */
    public static function withoutFragment(string $url): string
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

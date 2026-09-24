<?php

namespace App\Crawl;

/**
 * Links found on a page made into the URLs the crawler keeps.
 */
class Url
{
    /** The URL without its fragment. */
    public static function withoutFragment(string $url): string
    {
        return explode('#', $url, 2)[0];
    }

    /** Resolve a link (absolute, protocol-, root-, query- or path-relative) against its page. */
    public static function absolute(string $href, string $baseUrl): string
    {
        // Has a scheme: already absolute.
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) === 1) {
            return $href;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $origin = $scheme.'://'.($base['host'] ?? '').(isset($base['port']) ? ':'.$base['port'] : '');
        $path = $base['path'] ?? '/';

        // By the form of the link.
        return match (true) {
            str_starts_with($href, '//') => $scheme.':'.$href,
            str_starts_with($href, '/') => $origin.self::withoutDotSegments($href),
            str_starts_with($href, '?') => $origin.$path.$href,
            default => $origin.self::withoutDotSegments(rtrim(dirname($path), '/').'/'.$href),
        };
    }

    /** Resolve "." and ".." segments in a path, leaving the query string as it is. */
    private static function withoutDotSegments(string $path): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);
        $segments = [];

        // ".." pops, "." is dropped, the rest is kept.
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

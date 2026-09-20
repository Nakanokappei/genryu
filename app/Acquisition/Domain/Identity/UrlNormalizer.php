<?php

namespace App\Acquisition\Domain\Identity;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriNormalizer;
use GuzzleHttp\Psr7\UriResolver;
use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Canonical string form of a URL for identity purposes (ADR-0003). Two URLs
 * that differ only in tracking parameters, fragment, default port, case of
 * scheme/host, percent-encoding case or a trailing slash normalize to the
 * same string. Meaningful query parameters are kept, sorted by key.
 */
final class UrlNormalizer
{
    /**
     * Query parameters that never change what a page is. Exact names, plus
     * any name starting with "utm_". Profiles may add more (ADR-0003).
     */
    public const TRACKING_PARAMETERS = ['fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', 'source'];

    /**
     * @param  list<string>  $extraStripParameters  additional exact parameter names to drop
     */
    public static function normalize(string $url, array $extraStripParameters = []): string
    {
        $uri = self::parse($url);

        // Guzzle handles the RFC 3986 syntax-based normalizations: lowercase
        // scheme/host, uppercase percent-encoding, decode unreserved chars,
        // drop default port, resolve dot segments, "" path -> "/".
        $uri = UriNormalizer::normalize(
            $uri,
            UriNormalizer::CAPITALIZE_PERCENT_ENCODING
            | UriNormalizer::DECODE_UNRESERVED_CHARACTERS
            | UriNormalizer::CONVERT_EMPTY_PATH
            | UriNormalizer::REMOVE_DEFAULT_PORT
            | UriNormalizer::REMOVE_DOT_SEGMENTS,
        );

        $uri = $uri
            ->withFragment('')
            ->withQuery(self::normalizeQuery($uri->getQuery(), $extraStripParameters))
            ->withPath(self::normalizePath($uri->getPath()));

        return (string) $uri;
    }

    /**
     * Resolve a possibly relative reference against a base URL.
     */
    public static function resolve(string $base, string $reference): string
    {
        return (string) UriResolver::resolve(self::parse($base), new Uri(trim($reference)));
    }

    /**
     * Lower-cased host of a URL, or null when it has none.
     */
    public static function host(string $url): ?string
    {
        $host = self::parse($url)->getHost();

        return $host === '' ? null : $host;
    }

    /**
     * Parse an absolute http(s) URL or fail loudly.
     */
    private static function parse(string $url): UriInterface
    {
        $uri = new Uri(trim($url));

        if (! in_array($uri->getScheme(), ['http', 'https'], true) || $uri->getHost() === '') {
            throw new InvalidArgumentException("Not an absolute http(s) URL: {$url}");
        }

        return $uri;
    }

    /**
     * Drop tracking parameters and sort the remainder by name, keeping
     * repeated names in their original relative order.
     *
     * @param  list<string>  $extraStripParameters
     */
    private static function normalizeQuery(string $query, array $extraStripParameters): string
    {
        if ($query === '') {
            return '';
        }

        $strip = array_map('strtolower', [...self::TRACKING_PARAMETERS, ...$extraStripParameters]);
        $kept = [];

        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }

            $name = strtolower(explode('=', $pair, 2)[0]);

            if (str_starts_with($name, 'utm_') || in_array($name, $strip, true)) {
                continue;
            }

            $kept[] = $pair;
        }

        // Stable sort by the raw pair keeps identical names in input order.
        usort($kept, static fn (string $a, string $b): int => strcmp(explode('=', $a, 2)[0], explode('=', $b, 2)[0]));

        return implode('&', $kept);
    }

    /**
     * A trailing slash is meaningless on any path except the root.
     */
    private static function normalizePath(string $path): string
    {
        if ($path !== '/' && str_ends_with($path, '/')) {
            return rtrim($path, '/');
        }

        return $path;
    }
}

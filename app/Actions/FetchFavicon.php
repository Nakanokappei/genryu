<?php

namespace App\Actions;

use App\Crawl\Crawler;
use App\Crawl\Url;
use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Fetches a source's favicon (advertised icons, then /favicon.ico) and
 * keeps it under favicons/{source}.{ext}; rechecked with If-Modified-Since.
 * A failure leaves it blank.
 */
class FetchFavicon
{
    /** Accepted content types and the extension each is stored under. */
    public const ICON_TYPES = [
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /** Fetches the icon if missing, or rechecks it when $checkAgain; returns its path. */
    public function __invoke(Source $source, ?string $html = null, bool $checkAgain = false): ?string
    {
        // Already has one and no recheck asked.
        if ($source->favicon_path !== null && ! $checkAgain) {
            return $source->favicon_path;
        }

        // Has one with a known URL: recheck it.
        if ($source->favicon_path !== null && $source->favicon_url !== null) {
            return $this->refresh($source);
        }

        $html ??= $this->pageOrNothing($source->url);
        $candidates = array_unique([...self::advertisedIcons($html, $source->url), Url::absolute('/favicon.ico', $source->url)]);

        // First candidate that yields an icon wins.
        foreach ($candidates as $url) {
            try {
                $response = Crawler::client(10)->get($url);
            } catch (Throwable) {
                // Unreachable or forbidden: try the next.
                continue;
            }

            if ($this->keep($source, $url, $response)) {
                return $source->favicon_path;
            }
        }

        return null;
    }

    /** Rechecks the icon URL: 304 keeps, 200 replaces, 404/410 forgets the URL. */
    private function refresh(Source $source): ?string
    {
        try {
            $response = Crawler::client(10)
                ->withHeaders($source->favicon_modified_at !== null ? ['If-Modified-Since' => $source->favicon_modified_at->toRfc7231String()] : [])
                ->get((string) $source->favicon_url);
        } catch (Throwable) {
            // Unreachable: keep what is there.
            return $source->favicon_path;
        }

        // Not modified.
        if ($response->status() === 304) {
            return $source->favicon_path;
        }

        // Gone: look for the icon afresh next time.
        if ($response->status() === 404 || $response->status() === 410) {
            $source->update(['favicon_url' => null, 'favicon_modified_at' => null]);

            return $source->favicon_path;
        }

        $this->keep($source, (string) $source->favicon_url, $response);

        return $source->favicon_path;
    }

    /** Stores a response as the icon when it is one, with its URL and Last-Modified (or now). */
    private function keep(Source $source, string $url, Response $response): bool
    {
        $extension = self::extension($url, (string) $response->header('Content-Type'));

        // Not an icon.
        if (! $response->successful() || $extension === null || $response->body() === '') {
            return false;
        }

        $path = "favicons/{$source->id}.{$extension}";

        // Remove an old file under another extension.
        if ($source->favicon_path !== null && $source->favicon_path !== $path) {
            Storage::disk('local')->delete($source->favicon_path);
        }

        Storage::disk('local')->put($path, $response->body());

        $modified = rescue(fn () => CarbonImmutable::parse((string) $response->header('Last-Modified')), report: false);
        $source->update(['favicon_path' => $path, 'favicon_url' => $url, 'favicon_modified_at' => $response->hasHeader('Last-Modified') && $modified !== null ? $modified : now()]);

        return true;
    }

    /** The page's HTML, or '' when it cannot be fetched. */
    private function pageOrNothing(string $url): string
    {
        try {
            return Crawler::client()->get($url)->body();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Absolute URLs of the <link rel="…icon"> tags, in page order.
     *
     * @return list<string>
     */
    private static function advertisedIcons(string $html, string $baseUrl): array
    {
        if (preg_match_all('#<link\b[^>]*>#i', $html, $tags) === 0) {
            return [];
        }

        $icons = [];

        // Link tags whose rel contains icon and that have an href.
        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel\s*=\s*["\']?[^"\'>]*\bicon\b/i', $tag) === 1 && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href) === 1) {
                $icons[] = Url::absolute(html_entity_decode($href[1]), $baseUrl);
            }
        }

        return $icons;
    }

    /** The icon's extension, from its content type or else its URL. */
    private static function extension(string $url, string $contentType): ?string
    {
        $type = strtolower(trim(explode(';', $contentType)[0]));

        if (isset(self::ICON_TYPES[$type])) {
            return self::ICON_TYPES[$type];
        }

        $fromUrl = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));

        return in_array($fromUrl, self::ICON_TYPES, true) ? $fromUrl : null;
    }
}

<?php

namespace App\Actions;

use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The favicon of a source, shown next to its name on every screen. Fetched
 * when a page of the site is in hand (configuring the source, reading its
 * update list, fetching a document): the icons the page advertises with
 * <link rel="icon"> are tried in order, then /favicon.ico. Kept on the
 * local disk under favicons/{source}.{ext}, with the URL it came from and
 * its Last-Modified; a source that has one is checked again on every
 * update list with If-Modified-Since, so a changed icon is taken and an
 * unchanged one costs a 304. Decorative, so a site without one (or one
 * that cannot be fetched) is simply left blank and tried again next time.
 */
class FetchFavicon
{
    /** Image types a favicon may be served as, with the extension the file is kept under. */
    public const ICON_TYPES = [
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
        'image/png' => 'png',
        'image/svg+xml' => 'svg',
        'image/gif' => 'gif',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    /**
     * Fetch the icon of a source that has none; a source that has one is
     * asked about only when told to check again (the update list), with
     * If-Modified-Since. Without a page of the site in hand, the source
     * page is fetched for the icons it advertises.
     */
    public function __invoke(Source $source, ?string $html = null, bool $checkAgain = false): ?string
    {
        if ($source->favicon_path !== null && ! $checkAgain) {
            return $source->favicon_path;
        }

        // An icon already in hand: ask its URL whether it changed, and take it again only when it did.
        if ($source->favicon_path !== null && $source->favicon_url !== null) {
            return $this->refresh($source);
        }

        $html ??= $this->pageOrNothing($source->url);
        $candidates = array_unique([...self::advertisedIcons($html, $source->url), FetchUpdates::absolute('/favicon.ico', $source->url)]);

        foreach ($candidates as $url) {
            try {
                // robots.txt is enforced by the global HTTP middleware (AppServiceProvider).
                $response = Http::withUserAgent(FetchUpdates::USER_AGENT)->timeout(10)->get($url);
            } catch (Throwable) {
                // Forbidden, unreachable or faked away: try the next candidate.
                continue;
            }

            if ($this->keep($source, $url, $response)) {
                return $source->favicon_path;
            }
        }

        return null;
    }

    /**
     * Ask the icon's URL whether it changed since the icon was taken: a
     * 304 keeps what is there, a 200 with an icon replaces it, and a URL
     * that is gone (404) has the icon looked for afresh next time.
     */
    private function refresh(Source $source): ?string
    {
        try {
            $response = Http::withUserAgent(FetchUpdates::USER_AGENT)->timeout(10)
                ->withHeaders($source->favicon_modified_at !== null ? ['If-Modified-Since' => $source->favicon_modified_at->toRfc7231String()] : [])
                ->get((string) $source->favicon_url);
        } catch (Throwable) {
            return $source->favicon_path;
        }

        if ($response->status() === 304) {
            return $source->favicon_path;
        }

        if ($response->status() === 404 || $response->status() === 410) {
            $source->update(['favicon_url' => null, 'favicon_modified_at' => null]);

            return $source->favicon_path;
        }

        $this->keep($source, (string) $source->favicon_url, $response);

        return $source->favicon_path;
    }

    /**
     * Keep a response as the source's icon when it is one: the file on
     * disk, the URL, and its Last-Modified (or now, for a site that does
     * not say) for the next check.
     */
    private function keep(Source $source, string $url, Response $response): bool
    {
        $extension = self::extension($url, (string) $response->header('Content-Type'));

        if (! $response->successful() || $extension === null || $response->body() === '') {
            return false;
        }

        $path = "favicons/{$source->id}.{$extension}";

        if ($source->favicon_path !== null && $source->favicon_path !== $path) {
            Storage::disk('local')->delete($source->favicon_path);
        }

        Storage::disk('local')->put($path, $response->body());

        $modified = rescue(fn () => CarbonImmutable::parse((string) $response->header('Last-Modified')), report: false);
        $source->update(['favicon_path' => $path, 'favicon_url' => $url, 'favicon_modified_at' => $response->hasHeader('Last-Modified') && $modified !== null ? $modified : now()]);

        return true;
    }

    private function pageOrNothing(string $url): string
    {
        try {
            return Http::withUserAgent(FetchUpdates::USER_AGENT)->timeout(20)->get($url)->body();
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * The icons a page advertises (<link rel="icon" | "shortcut icon" |
     * "apple-touch-icon">), in page order, resolved against the page.
     *
     * @return list<string>
     */
    private static function advertisedIcons(string $html, string $baseUrl): array
    {
        if (preg_match_all('#<link\b[^>]*>#i', $html, $tags) === 0) {
            return [];
        }

        $icons = [];

        foreach ($tags[0] as $tag) {
            if (preg_match('/\brel\s*=\s*["\']?[^"\'>]*\bicon\b/i', $tag) === 1 && preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href) === 1) {
                $icons[] = FetchUpdates::absolute(html_entity_decode($href[1]), $baseUrl);
            }
        }

        return $icons;
    }

    /**
     * The file extension for an icon: from the content type it was served
     * with, or failing that from its URL.
     */
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

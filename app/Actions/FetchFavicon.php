<?php

namespace App\Actions;

use App\Models\Source;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The favicon of a source, shown next to its name on every screen. Fetched
 * the first time a page of the site is in hand and the source has none yet
 * (configuring it, reading its update list, fetching a document): the icons
 * the page advertises with <link rel="icon"> are tried in order, then
 * /favicon.ico. Kept on the local disk under favicons/{source}.{ext}.
 * Decorative, so a site without one (or one that cannot be fetched) is
 * simply left blank and tried again next time.
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
     * Fetch the icon unless the source already has one. Without a page of
     * the site in hand, the source page is fetched for the icons it
     * advertises.
     */
    public function __invoke(Source $source, ?string $html = null): ?string
    {
        if ($source->favicon_path !== null) {
            return $source->favicon_path;
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

            $extension = self::extension($url, (string) $response->header('Content-Type'));

            if (! $response->successful() || $extension === null || $response->body() === '') {
                continue;
            }

            $path = "favicons/{$source->id}.{$extension}";
            Storage::disk('local')->put($path, $response->body());
            $source->update(['favicon_path' => $path]);

            return $path;
        }

        return null;
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

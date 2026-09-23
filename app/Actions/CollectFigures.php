<?php

namespace App\Actions;

/**
 * 図版 (UI: "figures"): the figures of a primary source, gathered for its
 * material. A deterministic step, so no model is called: the document's
 * Markdown already carries every image of the body with an absolute URL
 * (App\Actions\ReadDocument), and each one is kept with its alt text and
 * its caption — the text that follows the image on its line, or the next
 * line when it reads as one (図1 …, Fig. 2 …). Icons and logos the pages
 * dress their text with are left out by name, and an image shown twice is
 * kept once, with the alt text and caption of whichever showing had them. A PDF's figures are drawings inside the file, with no URL of
 * their own, so a PDF document yields none.
 */
class CollectFigures
{
    /** What an image that is not a figure is called, in its file name or its alt text. */
    private const NOT_A_FIGURE = '/icon|logo|ロゴ|atmark|arrow|btn|button|banner|spacer|blank|pixel/iu';

    /** How a caption line begins. */
    private const CAPTION = '/^(図|表|写真|Fig\.?|Figure|Table|Photo|Abb\.?|Abbildung)\s*\d*/iu';

    /**
     * The figures of a document's Markdown, in the order they appear.
     *
     * @return list<array{url: string, alt: string, caption: ?string}>
     */
    public static function from(string $markdown): array
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $figures = [];

        foreach ($lines as $index => $line) {
            if (preg_match_all('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+"[^"]*")?\)/u', $line, $images, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($images as $position => $image) {
                // The fragment is a hint to the page's lazy loader, not part of the image.
                $url = (string) preg_replace('/#.*\z/u', '', $image[2][0]);
                $alt = trim($image[1][0]);

                if (preg_match(self::NOT_A_FIGURE, basename((string) parse_url($url, PHP_URL_PATH)).' '.$alt) === 1) {
                    continue;
                }

                $caption = $position === array_key_last($images) ? self::caption($line, $image, $lines, $index) : null;

                // Shown twice, it is kept once, with what either showing said of it.
                $figures[$url] = [
                    'url' => $url,
                    'alt' => ($figures[$url]['alt'] ?? '') !== '' ? $figures[$url]['alt'] : $alt,
                    'caption' => $figures[$url]['caption'] ?? $caption,
                ];
            }
        }

        return array_values($figures);
    }

    /**
     * The caption of the last image on a line: the text after it on the
     * same line, else the next non-empty line when it reads as a caption.
     *
     * @param  array<int, array{0: string, 1: int}>  $image
     * @param  list<string>  $lines
     */
    private static function caption(string $line, array $image, array $lines, int $index): ?string
    {
        $after = trim(substr($line, $image[0][1] + strlen($image[0][0])));

        if ($after !== '') {
            return $after;
        }

        foreach (array_slice($lines, $index + 1) as $next) {
            $next = trim($next);

            if ($next !== '') {
                return preg_match(self::CAPTION, $next) === 1 && ! str_contains($next, '![') ? $next : null;
            }
        }

        return null;
    }
}

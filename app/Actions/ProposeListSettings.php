<?php

namespace App\Actions;

use App\OpenAi\ChatCompletions;

/**
 * The agent that proposes a source's HTML list settings (item, title, date,
 * next-page selectors) from a list page. App\Jobs\ConfigureSource verifies
 * them on the page before saving.
 */
class ProposeListSettings
{
    /** Characters of HTML sent. */
    private const MAX_HTML_CHARS = 60000;

    /**
     * Asks the model and returns the proposed selectors.
     *
     * @return array{item: string, title: string, date: string, next: string}
     */
    public function __invoke(string $html, string $url): array
    {
        $proposal = ChatCompletions::send([
            'model' => (string) config('services.openai.model'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'developer', 'content' => self::instructions()],
                ['role' => 'user', 'content' => self::page($html, $url)],
            ],
        ]);

        return [
            'item' => trim((string) ($proposal['item'] ?? '')),
            'title' => trim((string) ($proposal['title'] ?? '')),
            'date' => trim((string) ($proposal['date'] ?? '')),
            'next' => trim((string) ($proposal['next'] ?? '')),
        ];
    }

    /** The fixed instruction. */
    private static function instructions(): string
    {
        return <<<'TEXT'
        You configure a deterministic crawler that reads a list of updates (news, press releases, calls) from an HTML page.
        Return a JSON object with exactly these keys, each a CSS selector string (empty string if none applies):
        - "item": selects every list entry element (a table row, li, article, div). Header rows may match; they are skipped when they have no link.
        - "title": selects, inside one item, the anchor whose text is the entry title and whose href is the entry URL.
        - "date": selects, inside one item, the element holding the publication date (a <time> element when present).
        - "next": selects, on the page, the single link to the next page of the list (rel="next", "next page", 次へ). Empty if the list has no pagination.
        Use the most stable selectors available (ids, semantic class names, element structure), never generated or positional ones. Do not invent elements that are not in the HTML.
        TEXT;
    }

    /** A page for a settings agent: URL, then HTML without scripts and styles, cut at MAX_HTML_CHARS. */
    public static function page(string $html, string $url): string
    {
        $html = (string) preg_replace('#<(script|style|svg|noscript)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);

        return "URL: {$url}\n\nHTML:\n".mb_substr($html, 0, self::MAX_HTML_CHARS);
    }
}

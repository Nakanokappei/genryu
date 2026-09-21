<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind the HTML list settings: given a list page, a cheap
 * model proposes the CSS selectors (item, title link, date, next-page
 * link). It only proposes; ConfigureSource verifies the proposal against
 * the page before anything is saved.
 */
class ProposeListSettings
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const MAX_HTML_CHARS = 60000;

    /**
     * @return array{item: string, title: string, date: string, next: string}
     */
    public function __invoke(string $html, string $url): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $response = Http::withToken($key)
            ->timeout(60)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.openai.model'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::instructions()],
                    ['role' => 'user', 'content' => "URL: {$url}\n\nHTML:\n".self::trim($html)],
                ],
            ])
            ->throw();

        $content = (string) $response->json('choices.0.message.content');
        $proposal = json_decode($content, true);

        if (! is_array($proposal)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return [
            'item' => trim((string) ($proposal['item'] ?? '')),
            'title' => trim((string) ($proposal['title'] ?? '')),
            'date' => trim((string) ($proposal['date'] ?? '')),
            'next' => trim((string) ($proposal['next'] ?? '')),
        ];
    }

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

    /**
     * The model needs the structure, not the scripts, styles or the tail of a very long page.
     */
    private static function trim(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|svg|noscript)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace('/\s+/', ' ', $html);

        return mb_substr($html, 0, self::MAX_HTML_CHARS);
    }
}

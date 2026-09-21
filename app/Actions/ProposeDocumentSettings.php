<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind the document settings: given one document page of a
 * source, a cheap model proposes the CSS selector of the element holding
 * the body, and selectors of things inside it to drop. It only proposes;
 * App\Jobs\FetchDocument verifies the proposal on the page before anything
 * is saved.
 */
class ProposeDocumentSettings
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const MAX_HTML_CHARS = 60000;

    /**
     * @return array{content: string, remove: string}
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

        $proposal = json_decode((string) $response->json('choices.0.message.content'), true);

        if (! is_array($proposal)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return [
            'content' => trim((string) ($proposal['content'] ?? '')),
            'remove' => trim((string) ($proposal['remove'] ?? '')),
        ];
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
        You configure a deterministic converter that turns one document page of a site (a news item, press release, call) into Markdown. The same selectors will be applied to every document page of this site, so choose what is stable across pages, not what is specific to this one.
        Return a JSON object with exactly these keys, each a CSS selector string (empty string if none applies):
        - "content": selects the single element holding the document body: its heading, date and text, and nothing else (no site navigation, sidebars, footers, cookie banners). Prefer <article>, <main> or the site's content container.
        - "remove": selects, inside the content element, what is not part of the body (share buttons, "related news", breadcrumbs, print links). Several selectors may be separated by commas.
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

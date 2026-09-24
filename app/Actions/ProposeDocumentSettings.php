<?php

namespace App\Actions;

use App\OpenAi\ChatCompletions;

/**
 * The agent behind the document settings: given one document page of a
 * source, a cheap model proposes the CSS selectors of the element holding
 * the body and of the one holding its date, of things inside the body to
 * drop, and of fixed text to move after the body. It only proposes;
 * App\Jobs\FetchDocument verifies the proposal on the page before anything
 * is saved.
 */
class ProposeDocumentSettings
{
    private const MAX_HTML_CHARS = 60000;

    /**
     * @return array{content: string, date: string, remove: string, fixed_text: string}
     */
    public function __invoke(string $html, string $url): array
    {
        $proposal = ChatCompletions::send([
            'model' => (string) config('services.openai.model'),
            'temperature' => 0,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'developer', 'content' => self::instructions()],
                ['role' => 'user', 'content' => "URL: {$url}\n\nHTML:\n".self::trim($html)],
            ],
        ]);

        $settings = [];

        foreach (ReadDocument::DOCUMENT_SETTING_KEYS as $key) {
            $settings[$key] = trim((string) ($proposal[$key] ?? ''));
        }

        return $settings;
    }

    private static function instructions(): string
    {
        return <<<'TEXT'
        You configure a deterministic converter that turns one document page of a site (a news item, press release, call) into Markdown. The same selectors will be applied to every document page of this site, so choose what is stable across pages, not what is specific to this one.
        Return a JSON object with exactly these keys, each a CSS selector string (empty string if none applies):
        - "content": selects the single element holding the document body: its heading, date and text, and nothing else (no site navigation, sidebars, footers, cookie banners). Prefer <article>, <main> or the site's content container.
        - "date": selects the element holding the publication or update date of the document (a <time>, or the line that shows the date), inside or outside the content element.
        - "remove": selects, inside the content element, what is not part of the body: share buttons, "related news", breadcrumbs, print links, "back to top" / "back to the list" links, pagers. Several selectors may be separated by commas.
        - "fixed_text": selects, inside the content element, fixed text that every document page repeats and that is not the body: copyright notices, disclaimers, notes on publication, contact boilerplate. It is kept but moved after the body. Several selectors may be separated by commas.
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

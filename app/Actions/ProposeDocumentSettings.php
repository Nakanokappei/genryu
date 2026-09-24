<?php

namespace App\Actions;

use App\OpenAi\ChatCompletions;

/**
 * The agent that proposes a source's document settings (UI 本文 / 日付 /
 * 除外 / 固定テキスト selectors) from one document page. The caller
 * verifies them on the page before saving.
 */
class ProposeDocumentSettings
{
    /**
     * Asks the model and returns the proposed selectors.
     *
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
                ['role' => 'user', 'content' => ProposeListSettings::page($html, $url)],
            ],
        ]);

        $settings = [];

        foreach (ReadDocument::DOCUMENT_SETTING_KEYS as $key) {
            $settings[$key] = trim((string) ($proposal[$key] ?? ''));
        }

        return $settings;
    }

    /** The fixed instruction. */
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
}

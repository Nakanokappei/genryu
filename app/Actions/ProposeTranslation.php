<?php

namespace App\Actions;

use App\Enums\Language;
use App\Models\Article;
use App\Models\LanguageSetting;
use App\OpenAi\Responses;

/**
 * The agent of 翻訳 (UI "Translation"): translates an article into another
 * language, with the primary source and material as context for terms.
 * App\Jobs\TranslateArticle saves it.
 */
class ProposeTranslation
{
    /** Characters of the body sent. */
    private const MAX_MARKDOWN_CHARS = 60000;

    /** Fixed instruction sent after the cached policy (%s: target language); shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'Translate the article below into %s. The primary source and the material follow it as context for terms, names and numbers only: never translate them instead of the article, and never let them add to it or correct it. Return the title and the body in the target language. Keep the line of five hyphens (-----) that sets the lead apart from the rest of the body exactly as it is, where it is.';

    /**
     * Sends the request and returns the answer and usage.
     *
     * @param  array<string, mixed>  $material  the material the article was written from, as context
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, string $language, array $material, string $documentTitle, string $url): array
    {
        return Responses::send(self::request($policy, $model, $article, $language, $material, $documentTitle, $url));
    }

    /**
     * The request: cached policy, language prompts, instruction, then the article and its context.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, Article $article, string $language, array $material, string $documentTitle, string $url): array
    {
        $name = Language::nameOf($language);
        $input = 'Article to translate (written in '.Language::nameOf($article->language)."):\n\n"
            ."# {$article->headline}\n\n".mb_substr((string) $article->body, 0, self::MAX_MARKDOWN_CHARS)
            ."\n\n---\n\nContext, not to be translated in place of the article.\n\nPrimary source: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n"
            .json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Responses::request($model, [
            Responses::policy($policy),
            // 言語別の追加プロンプト (UI "Additional prompt per language").
            ...LanguageSetting::messages($language),
            ['role' => 'developer', 'content' => sprintf(self::INSTRUCTIONS, "{$name} ({$language})")],
            ['role' => 'user', 'content' => $input],
        ], 'translation', [
            'type' => 'object',
            'properties' => [
                'title' => ['type' => 'string'],
                'body' => ['type' => 'string'],
            ],
            'required' => ['title', 'body'],
            'additionalProperties' => false,
        ]);
    }
}

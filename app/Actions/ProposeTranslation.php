<?php

namespace App\Actions;

use App\Enums\Language;
use App\Models\Article;
use App\Models\LanguageSetting;
use App\OpenAi\Responses;

/**
 * The agent behind 翻訳 (stage 2.4 of docs/HANDOVER.md, the other
 * languages): given the translation layer of the editorial policy and an
 * article as it was written, a model renders it in another language. It
 * is handed the primary source and the material as well, because a
 * translator without the context mistranslates the terms — LLM becomes
 * a master of laws when nobody said the article was about language
 * models. The context is there to settle terms, names and numbers, never
 * to add or correct anything.
 *
 * The call goes to the Responses API like the others: the policy as the
 * developer message carrying an explicit prompt-cache breakpoint, so the
 * policy, the same for every translation, is served from the cache, then
 * what changes per translation after it. It only proposes;
 * App\Jobs\TranslateArticle checks a title and a body are there before
 * anything is saved.
 */
class ProposeTranslation
{
    private const MAX_MARKDOWN_CHARS = 60000;

    /** What the model is told after the cached policy: which language to write, and what the context is for. Shown on the screen under the prompt, so nobody puts a placeholder in the prompt for it. */
    public const INSTRUCTIONS = 'Translate the article below into %s. The primary source and the material follow it as context for terms, names and numbers only: never translate them instead of the article, and never let them add to it or correct it. Return the title and the body in the target language. Keep the line of five hyphens (-----) that sets the lead apart from the rest of the body exactly as it is, where it is.';

    /**
     * @param  array<string, mixed>  $material  the material the article was written from, as context
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, string $language, array $material, string $documentTitle, string $url): array
    {
        return Responses::send(self::request($policy, $model, $article, $language, $material, $documentTitle, $url));
    }

    /**
     * The request: the policy first, as the developer message, with the
     * cache breakpoint on it; the target language after it; then the
     * article to translate, and the source and material as context.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, Article $article, string $language, array $material, string $documentTitle, string $url): array
    {
        $name = Language::nameOf($language);
        $input = 'Article to translate (written in '.Language::nameOf($article->language)."):\n\n"
            ."# {$article->title}\n\n".mb_substr((string) $article->body, 0, self::MAX_MARKDOWN_CHARS)
            ."\n\n---\n\nContext, not to be translated in place of the article.\n\nPrimary source: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n"
            .json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return Responses::request($model, [
            Responses::policy($policy),
            // What belongs to the language translated into (言語別の追加プロンプト), then the fixed instruction.
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

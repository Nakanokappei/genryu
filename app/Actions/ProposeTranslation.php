<?php

namespace App\Actions;

use App\Models\Article;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const MAX_MARKDOWN_CHARS = 60000;

    /** What the model is told after the cached policy: which language to write, and what the context is for. */
    private const INSTRUCTIONS = 'Translate the article below into %s. The primary source and the material follow it as context for terms, names and numbers only: never translate them instead of the article, and never let them add to it or correct it. Return the title and the body in the target language.';

    /**
     * @param  array<string, mixed>  $material  the material the article was written from, as context
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, string $language, array $material, string $documentTitle, string $url): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $article, $language, $material, $documentTitle, $url))
            ->throw();

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $json = json_decode(self::outputText($response->json()), true);

        if (! is_array($json)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return [
            'json' => $json,
            'usage' => [
                'input_tokens' => self::count($response->json('usage.input_tokens')),
                'cached_tokens' => self::count($response->json('usage.input_tokens_details.cached_tokens')),
                'cache_write_tokens' => self::count($response->json('usage.input_tokens_details.cache_write_tokens')),
                'output_tokens' => self::count($response->json('usage.output_tokens')),
                'latency_ms' => $latency,
            ],
        ];
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
        $name = Article::LANGUAGE_NAMES[$language] ?? $language;
        $input = 'Article to translate (written in '.(Article::LANGUAGE_NAMES[(string) $article->language] ?? (string) $article->language)."):\n\n"
            ."# {$article->title}\n\n".mb_substr((string) $article->body, 0, self::MAX_MARKDOWN_CHARS)
            ."\n\n---\n\nContext, not to be translated in place of the article.\n\nPrimary source: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n"
            .json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        ['type' => 'input_text', 'text' => $policy, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                    ],
                ],
                ['role' => 'developer', 'content' => sprintf(self::INSTRUCTIONS, "{$name} ({$language})")],
                ['role' => 'user', 'content' => $input],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'translation',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'body' => ['type' => 'string'],
                        ],
                        'required' => ['title', 'body'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * The text of the answer: the output_text of the first message in
     * the output (reasoning items and the like are passed over).
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function outputText(?array $body): string
    {
        foreach ($body['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    return (string) $content['text'];
                }
            }
        }

        return (string) ($body['output_text'] ?? '');
    }

    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

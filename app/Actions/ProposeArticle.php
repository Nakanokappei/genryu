<?php

namespace App\Actions;

use App\Models\Article;
use App\Models\LanguageSetting;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind 記事 (UI: "Articles", stage 2.4 of docs/HANDOVER.md):
 * given the article generation layer of the editorial policy, a
 * material (the JSON extracted from one document) and the headline
 * App\Jobs\RefineHeadline settled on, a model proposes the body of the
 * article under that headline, in Markdown, and the language it wrote
 * in, which is the language of the material and so of the primary
 * source; the other languages are translated from it. The call goes to the Responses
 * API like the screening's and the material's: the policy as the
 * developer message carrying an explicit prompt-cache breakpoint, so the
 * policy, the same for every article, is served from the cache, then
 * what changes per article after it. The usage the API reports comes
 * back with the proposal. It only proposes; App\Jobs\GenerateArticle
 * checks a body is there before anything is saved.
 */
class ProposeArticle
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** What the model is told after the cached policy: what the input is, that nothing may be added to it, and to say which language it wrote in. Shown on the screen under the prompt, so nobody puts a placeholder in the prompt for it. */
    public const INSTRUCTIONS = 'The material below was drawn from one primary-source document, and the headline of its article has already been settled. Write the body of the article under that headline, following the policy above, in the language the material is written in. Use only what the material says; never invent facts, figures or quotes that are not in it. Then, having written the body, write its lead in `lead`: one short paragraph that sums up the whole article — what changed and why it matters to the reader — so that someone who reads only the headline and the lead knows what the article says. The lead stands above the body and reads on its own: never begin it with a conjunction or refer back to anything, do not repeat the headline word for word, and say nothing the body does not say. The body itself starts with the opening, not with the lead. Name the language in `language`. When the source has numbered figures, quote at least one and at most two of them in `figures`, by number: the ones that best show what the body talks about, each with the section whose text it illustrates — opening (before the first ## heading), background (the first ## section), technology (the second) or outlook (the third). Leave `figures` empty only when the source has no figures.';

    /**
     * @param  array<string, mixed>  $material
     * @param  array{body: string, problem: string}|null  $revision  a body already written and what is wrong with it, when it is to be written again
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, array $material, string $headline, string $documentTitle, string $url, ?array $revision = null, ?string $language = null): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $material, $headline, $documentTitle, $url, $revision, $language))
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
     * cache breakpoint on it; the instructions after it; the headline,
     * then the material with the document it came from last; the answer
     * constrained to a Markdown body and its language. A rewrite adds
     * the body already written and what is wrong with it after that.
     *
     * @param  array<string, mixed>  $material
     * @param  array{body: string, problem: string}|null  $revision
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, array $material, string $headline, string $documentTitle, string $url, ?array $revision = null, ?string $language = null): array
    {
        $input = [
            [
                'role' => 'developer',
                'content' => [
                    ['type' => 'input_text', 'text' => $policy, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                ],
            ],
            // What belongs to the language the body is written in (言語別の追加プロンプト), then the fixed instruction.
            ...LanguageSetting::messages($language),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS],
            ['role' => 'user', 'content' => "Headline: {$headline}\n\nSource document: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).self::figureList($material)],
        ];

        // A body to be written again is shown with what is wrong with it, so the rewrite fixes that and keeps the rest.
        if ($revision !== null) {
            $input[] = ['role' => 'user', 'content' => "The body you wrote:\n\n{$revision['body']}\n\nWhat is wrong with it:\n{$revision['problem']}\n\nWrite it again, fixing that and keeping everything else."];
        }

        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => $input,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'article',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'body' => ['type' => 'string'],
                            // After the body, so it is written as a summary of the body just written.
                            'lead' => ['type' => 'string'],
                            // Which language it wrote in, so the job knows what is left to translate into.
                            'language' => ['type' => 'string', 'enum' => Article::SOURCE_LANGUAGES],
                            // The figures of the source to quote, by number, and where each stands; App\Jobs\GenerateArticle keeps only valid ones.
                            'figures' => ['type' => 'array', 'items' => [
                                'type' => 'object',
                                'properties' => ['figure' => ['type' => 'integer'], 'section' => ['type' => 'string', 'enum' => Article::FIGURE_SECTIONS]],
                                'required' => ['figure', 'section'],
                                'additionalProperties' => false,
                            ]],
                        ],
                        'required' => ['body', 'lead', 'language', 'figures'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
        ];
    }

    /**
     * The figures of the source, numbered from 1 in the order they appear,
     * for the writer to quote by number; nothing when there are none.
     *
     * @param  array<string, mixed>  $material
     */
    private static function figureList(array $material): string
    {
        $figures = array_values((array) ($material['figures'] ?? []));

        if ($figures === []) {
            return '';
        }

        $lines = array_map(fn (array $figure, int $i): string => ($i + 1).'. '.trim(($figure['alt'] ?? '').' '.($figure['caption'] ?? '')), $figures, array_keys($figures));

        return "\n\nFigures of the source (quote by number):\n".implode("\n", $lines);
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

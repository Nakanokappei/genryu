<?php

namespace App\Actions;

use App\Models\Article;
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
    public const INSTRUCTIONS = 'The material below was drawn from one primary-source document, and the headline of its article has already been settled. Write the body of the article under that headline, following the policy above, in the language the material is written in. Use only what the material says; never invent facts, figures or quotes that are not in it. Name that language in `language`.';

    /**
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, array $material, string $headline, string $documentTitle, string $url): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $material, $headline, $documentTitle, $url))
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
     * constrained to a Markdown body and its language.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, array $material, string $headline, string $documentTitle, string $url): array
    {
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
                ['role' => 'developer', 'content' => self::INSTRUCTIONS],
                ['role' => 'user', 'content' => "Headline: {$headline}\n\nSource document: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'article',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'body' => ['type' => 'string'],
                            // Which language it wrote in, so the job knows what is left to translate into.
                            'language' => ['type' => 'string', 'enum' => Article::SOURCE_LANGUAGES],
                        ],
                        'required' => ['body', 'language'],
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

<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind 記事 (UI: "Articles", stage 2.4 of docs/HANDOVER.md):
 * given the article generation layer of the editorial policy and a
 * material (the JSON extracted from one document), a model proposes the
 * article as a title and a Markdown body. The call goes to the Responses
 * API like the screening's and the material's: the policy as the
 * developer message carrying an explicit prompt-cache breakpoint, so the
 * policy, the same for every article, is served from the cache, then
 * what changes per article after it. The usage the API reports comes
 * back with the proposal. It only proposes; App\Jobs\GenerateArticle
 * checks a title and a body are there before anything is saved.
 */
class ProposeArticle
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** What the model is told after the cached policy: what the input is, and that nothing may be added to it. */
    private const INSTRUCTIONS = 'You write one article for a technology-watch publication from the material below: the facts extracted from one primary-source document (an official announcement, press release, call or report), following the policy above. Use only what the material says; never invent facts, figures or quotes that are not in it.';

    /**
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, array $material, string $documentTitle, string $url): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $material, $documentTitle, $url))
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
     * cache breakpoint on it; the instructions after it; the material
     * with the document it came from last; the answer constrained to a
     * title and a Markdown body.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, array $material, string $documentTitle, string $url): array
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
                ['role' => 'user', 'content' => "Source document: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'article',
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

<?php

namespace App\Actions;

use App\Models\Screening;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind スクリーニング (UI: "Screening", the Editorial Screening
 * Gate): given the content filtering prompt of the editorial policy and
 * a document's Markdown, a model decides adopt / reject / review with a
 * reason class, the fact in the document it rests on and a short reason.
 * The call goes to the Responses API with the prompt as the developer
 * message carrying an explicit prompt-cache breakpoint, so that the
 * prompt, the same for every document, is served from the cache, and
 * everything that changes per document comes after it. The usage the
 * API reports (cached and cache-written tokens apart) comes back with
 * the decision. It only proposes; App\Jobs\ScreenDocument keeps the run.
 */
class ProposeDecision
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const MAX_MARKDOWN_CHARS = 120000;

    /**
     * @return array{decision: string, primary_reason: string, evidence: string, reason: string, input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}
     */
    public function __invoke(string $prompt, string $model, string $markdown): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(180)
            ->post(self::ENDPOINT, self::request($prompt, $model, $markdown))
            ->throw();

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $decision = json_decode(self::outputText($response->json()), true);

        if (! is_array($decision) || ! in_array(strtolower((string) ($decision['decision'] ?? '')), Screening::DECISIONS, true)) {
            throw new RuntimeException(__('The agent did not return a decision.'));
        }

        return [
            'decision' => strtolower((string) $decision['decision']),
            'primary_reason' => (string) ($decision['primary_reason'] ?? ''),
            'evidence' => (string) ($decision['evidence'] ?? ''),
            'reason' => (string) ($decision['reason'] ?? ''),
            'input_tokens' => self::count($response->json('usage.input_tokens')),
            'cached_tokens' => self::count($response->json('usage.input_tokens_details.cached_tokens')),
            'cache_write_tokens' => self::count($response->json('usage.input_tokens_details.cache_write_tokens')),
            'output_tokens' => self::count($response->json('usage.output_tokens')),
            'latency_ms' => $latency,
        ];
    }

    /**
     * The request: the fixed prompt first, as the developer message, with
     * the explicit cache breakpoint on it; the document after it, as the
     * user message; the answer constrained to the decision's JSON.
     *
     * @return array<string, mixed>
     */
    public static function request(string $prompt, string $model, string $markdown): array
    {
        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                    ],
                ],
                [
                    'role' => 'user',
                    'content' => mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS),
                ],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'screening_decision',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'decision' => ['type' => 'string', 'enum' => ['ADOPT', 'REJECT', 'REVIEW']],
                            'primary_reason' => ['type' => 'string', 'enum' => Screening::PRIMARY_REASONS],
                            'evidence' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                        'required' => ['decision', 'primary_reason', 'evidence', 'reason'],
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

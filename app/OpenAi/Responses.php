<?php

namespace App\OpenAi;

use RuntimeException;

/**
 * The call every agent makes to OpenAI's Responses API. The agents share
 * one shape: the editorial policy first, as the developer message with
 * an explicit prompt-cache breakpoint, so the same policy is served from
 * the cache call after call; what changes per call after it; the answer
 * constrained to a JSON schema. What differs from agent to agent — the
 * instruction, the input and the schema — stays with the agent; sending,
 * reading the answer and counting the usage are here.
 */
class Responses
{
    /**
     * The request around an agent's input: the model, explicit prompt
     * caching, and the answer constrained to the schema under its name.
     *
     * @param  list<array<string, mixed>>  $input
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function request(string $model, array $input, string $name, array $schema): array
    {
        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => $input,
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $name,
                    'strict' => true,
                    'schema' => $schema,
                ],
            ],
        ];
    }

    /**
     * The policy as the first message: a developer message whose text
     * carries the explicit cache breakpoint, so everything up to it is
     * the cached prefix.
     *
     * @return array<string, mixed>
     */
    public static function policy(string $policy): array
    {
        return [
            'role' => 'developer',
            'content' => [
                ['type' => 'input_text', 'text' => $policy, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
            ],
        ];
    }

    /**
     * Send the request and return the answer's JSON with the usage the
     * API reports (cached and cache-written tokens apart) and the time the
     * call took. An answer that is not a JSON object fails with $invalid,
     * which an agent may word for what it expected.
     *
     * @param  array<string, mixed>  $request
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public static function send(array $request, int $timeout = 300, string $invalid = 'The agent did not return valid JSON.'): array
    {
        $started = hrtime(true);
        $response = Client::post('responses', $request, $timeout);

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $json = json_decode(self::outputText($response->json()), true);

        if (! is_array($json)) {
            throw new RuntimeException(__($invalid));
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

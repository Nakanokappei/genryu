<?php

namespace App\OpenAi;

use RuntimeException;

/** The Responses API call every agent shares: cached policy first, JSON-schema answer. */
class Responses
{
    /**
     * The request envelope: model, explicit prompt caching, the input and the named schema.
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
     * The policy as a developer message ending in the cache breakpoint.
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
     * Sends the request; returns the answer's JSON and the usage. A non-object answer throws $invalid.
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

        // Not a JSON object: fail.
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
     * The first output_text of the first message item, skipping reasoning items.
     *
     * @param  array<string, mixed>|null  $body
     */
    private static function outputText(?array $body): string
    {
        // Look through the output items for a message.
        foreach ($body['output'] ?? [] as $item) {
            if (($item['type'] ?? '') !== 'message') {
                continue;
            }

            // Its first output_text is the answer.
            foreach ($item['content'] ?? [] as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    return (string) $content['text'];
                }
            }
        }

        return (string) ($body['output_text'] ?? '');
    }

    /** A reported token count, or null when absent. */
    private static function count(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}

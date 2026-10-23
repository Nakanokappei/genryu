<?php

namespace App\OpenAi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/** The one way out to the OpenAI API. */
class Client
{
    private const BASE_URL = 'https://api.openai.com/v1/';

    /**
     * Posts to an endpoint with the API key, throwing on an error status.
     *
     * @param  string  $path  the endpoint under /v1/, e.g. "responses"
     * @param  array<string, mixed>  $body
     */
    public static function post(string $path, array $body, int $timeout): Response
    {
        $key = (string) config('services.openai.key');

        // No key, no call.
        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        // Today's tokens spent: no more calls until tomorrow.
        $limit = (int) config('services.openai.daily_token_limit');
        if ($limit > 0 && self::tokensToday() >= $limit) {
            throw new RuntimeException(__('The OpenAI daily limit of :limit tokens is reached; calls resume tomorrow.', ['limit' => number_format($limit)]));
        }

        $response = Http::withToken($key)->timeout($timeout)->post(self::BASE_URL.$path, $body)->throw();
        self::countTokens($path, $response);

        return $response;
    }

    /** The tokens the generating calls have used today (Japan time), counted by every worker alike. */
    public static function tokensToday(): int
    {
        return (int) Cache::get(self::todayKey(), 0);
    }

    /** Adds a response's tokens to today's count; embeddings are cheap and not counted. */
    private static function countTokens(string $path, Response $response): void
    {
        // Embeddings: not counted.
        if ($path === 'embeddings') {
            return;
        }

        $tokens = (int) ($response->json('usage.input_tokens') ?? $response->json('usage.prompt_tokens') ?? 0)
            + (int) ($response->json('usage.output_tokens') ?? $response->json('usage.completion_tokens') ?? 0);
        Cache::add(self::todayKey(), 0, now()->addDays(2));
        Cache::increment(self::todayKey(), $tokens);
    }

    /** Today's counter in the cache, by the date in the display timezone. */
    private static function todayKey(): string
    {
        return 'openai-tokens:'.now((string) config('app.display_timezone'))->toDateString();
    }
}

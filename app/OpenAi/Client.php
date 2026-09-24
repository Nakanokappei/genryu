<?php

namespace App\OpenAi;

use Illuminate\Http\Client\Response;
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

        return Http::withToken($key)->timeout($timeout)->post(self::BASE_URL.$path, $body)->throw();
    }
}

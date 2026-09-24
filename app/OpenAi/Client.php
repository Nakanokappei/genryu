<?php

namespace App\OpenAi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every call to the OpenAI API goes out here: the API key checked before
 * anything is sent, the request posted under it, an error status thrown
 * as an exception. What is sent and how the answer is read stay with the
 * caller (Responses, ChatCompletions, the image and embedding actions).
 */
class Client
{
    private const BASE_URL = 'https://api.openai.com/v1/';

    /**
     * @param  string  $path  the endpoint under /v1/, e.g. "responses"
     * @param  array<string, mixed>  $body
     */
    public static function post(string $path, array $body, int $timeout): Response
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        return Http::withToken($key)->timeout($timeout)->post(self::BASE_URL.$path, $body)->throw();
    }
}

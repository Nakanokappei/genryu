<?php

namespace App\OpenAi;

use RuntimeException;

/** The Chat Completions call of the two settings agents (ProposeListSettings, ProposeDocumentSettings). */
class ChatCompletions
{
    /**
     * Sends the request and returns the answer's JSON object.
     *
     * @param  array<string, mixed>  $request
     * @return array<mixed>
     */
    public static function send(array $request, int $timeout = 60): array
    {
        $response = Client::post('chat/completions', $request, $timeout);
        $json = json_decode((string) $response->json('choices.0.message.content'), true);

        // Not a JSON object: fail.
        if (! is_array($json)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return $json;
    }
}

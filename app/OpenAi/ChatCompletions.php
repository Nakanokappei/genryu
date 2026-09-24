<?php

namespace App\OpenAi;

use RuntimeException;

/**
 * The call the two settings agents (ProposeListSettings,
 * ProposeDocumentSettings) make to the Chat Completions API: the answer
 * asked for as a JSON object and read back as one. The request itself
 * stays with the agent.
 */
class ChatCompletions
{
    /**
     * Send the request and return the answer's JSON object.
     *
     * @param  array<string, mixed>  $request
     * @return array<mixed>
     */
    public static function send(array $request, int $timeout = 60): array
    {
        $response = Client::post('chat/completions', $request, $timeout);
        $json = json_decode((string) $response->json('choices.0.message.content'), true);

        if (! is_array($json)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return $json;
    }
}

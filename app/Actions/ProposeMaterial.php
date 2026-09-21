<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind 素材情報 (UI: "Materials", stage 2.3 of docs/HANDOVER.md):
 * given the structuring layer of the editorial policy and a document's
 * Markdown, a model proposes the material as a JSON object whose keys are
 * the policy's items. It only proposes; App\Jobs\ExtractMaterial checks
 * the items are all there before anything is saved.
 */
class ProposeMaterial
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    private const MAX_MARKDOWN_CHARS = 60000;

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $policy, string $markdown, string $url): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $response = Http::withToken($key)
            ->timeout(120)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.openai.model'),
                'temperature' => 0,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'system', 'content' => self::instructions($policy)],
                    ['role' => 'user', 'content' => "URL: {$url}\n\nDocument (Markdown):\n".mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS)],
                ],
            ])
            ->throw();

        $proposal = json_decode((string) $response->json('choices.0.message.content'), true);

        if (! is_array($proposal)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
        }

        return $proposal;
    }

    /**
     * The policy is the prompt; these lines only pin down the output shape.
     */
    private static function instructions(string $policy): string
    {
        return <<<TEXT
        You structure one document from a primary source (an official announcement, press release, call or report) for a technology-watch publication, following the editorial policy below.
        Return a JSON object with one key per item listed in the policy, using the item names exactly as written there. Fill every item: a string, a list of strings, or null when the document gives nothing for it. Never invent facts that are not in the document.

        Editorial policy:
        {$policy}
        TEXT;
    }
}

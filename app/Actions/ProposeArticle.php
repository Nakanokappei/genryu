<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind 記事 (UI: "Articles", stage 2.4 of docs/HANDOVER.md):
 * given the article generation layer of the editorial policy and a
 * material (the JSON extracted from one document), a model proposes the
 * article as a title and a Markdown body. It only proposes;
 * App\Jobs\GenerateArticle checks both are there before anything is saved.
 */
class ProposeArticle
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    /**
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public function __invoke(string $policy, array $material, string $documentTitle, string $url): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $response = Http::withToken($key)
            ->timeout(180)
            ->post(self::ENDPOINT, [
                'model' => (string) config('services.openai.model'),
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'developer', 'content' => self::instructions($policy)],
                    ['role' => 'user', 'content' => "Source document: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
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
     * The policy is the prompt; these lines only pin down the input and the output shape.
     */
    private static function instructions(string $policy): string
    {
        return <<<TEXT
        You write one article for a technology-watch publication from the material below: the facts extracted from one primary-source document (an official announcement, press release, call or report), following the editorial policy.
        Return a JSON object with two keys: "title" (the headline, one line) and "body" (the article, in Markdown). Use only what the material says; never invent facts, figures or quotes that are not in it.

        Editorial policy:
        {$policy}
        TEXT;
    }
}

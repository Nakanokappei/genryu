<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The writer of 見出し (the headline), the first step of an article:
 * given the headline layer of the editorial policy and a material, a
 * model writes the headline in the language of the material, and so of
 * the primary source, in four steps (the topic word, a plain draft, the
 * assumption the draft rests on, the line that denies it) before any
 * body exists. When a headline was already tried it is also given the
 * scores the last one fell short on rather than a headline to imitate,
 * and the headlines already tried so that it does not circle back to
 * one. It only proposes; App\Jobs\RefineHeadline scores what comes back
 * and keeps the best.
 */
class ProposeHeadline
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** What the model is told after the cached policy. Shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'The material below was drawn from one primary-source document, and no article has been written from it yet. Write the headline of that article, following the policy above, in the language the material is written in; the article will be written under it. Use only what the material says. When headlines were already tried, the review of the last one says what to change: change that, do not tune the wording of a headline that failed on what it says, and never reuse a headline already tried.';

    /**
     * @param  array<string, mixed>  $material
     * @param  array<string, mixed>|null  $review  what the judge said of the last headline, or null for the first
     * @param  list<string>  $tried  the headlines already scored
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, array $material, ?array $review = null, array $tried = []): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $material, $review, $tried))
            ->throw();

        $latency = (int) round((hrtime(true) - $started) / 1_000_000);
        $json = json_decode(self::outputText($response->json()), true);

        if (! is_array($json)) {
            throw new RuntimeException(__('The agent did not return valid JSON.'));
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
     * The request: the policy first, as the developer message, with the
     * cache breakpoint on it; the rubric and the instruction after it;
     * then, when headlines were tried, what was tried and what the judge
     * said; the material last. The answer walks the four steps in the
     * order the schema names them, and only the last is kept.
     *
     * @param  array<string, mixed>  $material
     * @param  array<string, mixed>|null  $review
     * @param  list<string>  $tried
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, array $material, ?array $review, array $tried): array
    {
        $input = '';

        // A rewrite is told what fell short; the first headline is written from the material alone.
        if ($review !== null) {
            $shortfall = [];

            foreach (ScoreHeadline::COMMON as $key => $item) {
                $shortfall[] = "- {$key}: ".(int) ($review['common'][$key] ?? 0).' / '.$item['points'];
            }

            $input = "Headlines already tried, none of which may be used again:\n- ".implode("\n- ", $tried)
                ."\n\nWhat the judge said of the last one:\n".($review['what_to_fix'] ?? '')
                ."\n\nWhere it fell short (score / points):\n".implode("\n", $shortfall)
                .($review['musts_failed'] === [] ? '' : "\n\nIt failed outright on: ".implode(', ', $review['musts_failed']))
                ."\n\n";
        }

        $input .= "Material (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'model' => $model,
            'prompt_cache_options' => ['mode' => 'explicit'],
            'input' => [
                [
                    'role' => 'developer',
                    'content' => [
                        ['type' => 'input_text', 'text' => $policy, 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                    ],
                ],
                ['role' => 'developer', 'content' => self::INSTRUCTIONS."\n\n".ScoreHeadline::rubric()],
                ['role' => 'user', 'content' => $input],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'headline',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'topic_word' => ['type' => 'string'],
                            'title_draft' => ['type' => 'string'],
                            'assumption' => ['type' => 'string'],
                            'headline' => ['type' => 'string'],
                        ],
                        'required' => ['topic_word', 'title_draft', 'assumption', 'headline'],
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

<?php

namespace App\Actions;

use App\Models\EditorialPolicy;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The agent behind 素材情報 (UI: "Materials", stage 2.3 of docs/HANDOVER.md):
 * given the structuring layer of the editorial policy and a document's
 * Markdown, a model fills every item the policy lists, says where each
 * one comes from — the document, quoting the lines it rests on, or its
 * own general knowledge, which is what this project is out to test —
 * and leaves as null what neither gives. The call goes to
 * the Responses API: the policy as the developer message carrying an
 * explicit prompt-cache breakpoint, so the same policy is served from
 * the cache document after document, then the document with its lines
 * numbered. The answer is constrained to a schema built from the
 * policy's items; App\Actions\ValidateMaterial then checks that every
 * quote really is in the lines it names, and a miss is repaired once
 * with the errors in hand. It only proposes; App\Jobs\ExtractMaterial
 * keeps the material.
 */
class ProposeMaterial
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    private const MAX_MARKDOWN_CHARS = 120000;

    /** Where an item's value comes from: the document, the model's general knowledge, or nowhere. */
    public const SOURCES = ['document', 'knowledge', 'none'];

    /** What the model is told after the cached policy, and what a repair adds. */
    private const INSTRUCTIONS = 'The document below has its lines numbered "N| " for your quotes; the numbers are not part of the text. Fill every item of the policy. An item taken from the document has source "document" and the quotes it rests on: the exact text as written, with the first and last line it spans (1-based, inclusive). An item you fill from your own general knowledge, to give the reader what the document assumes, has source "knowledge" and no quotes; never use it for what was achieved here. An item neither gives has source "none", a null value and no quotes.';

    private const REPAIR = 'The previous answer failed these checks; return the corrected answer, fixing every item listed and changing nothing else.';

    /**
     * @param  list<string>  $errors  what the previous answer got wrong, for a repair
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, string $markdown, array $errors = []): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $markdown, $errors))
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
     * cache breakpoint on it; the instructions and any repair after it;
     * the document, its lines numbered, last.
     *
     * @param  list<string>  $errors
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, string $markdown, array $errors = []): array
    {
        $instructions = [self::INSTRUCTIONS];

        if ($errors !== []) {
            $instructions[] = self::REPAIR."\n- ".implode("\n- ", $errors);
        }

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
                ['role' => 'developer', 'content' => implode("\n\n", $instructions)],
                ['role' => 'user', 'content' => "Document:\n".self::numbered(mb_substr($markdown, 0, self::MAX_MARKDOWN_CHARS))],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'material',
                    'strict' => true,
                    'schema' => self::schema(EditorialPolicy::items($policy)),
                ],
            ],
        ];
    }

    /**
     * The document with its lines numbered, so the model can point at them.
     */
    public static function numbered(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];

        return implode("\n", array_map(fn (int $index, string $line): string => ($index + 1).'| '.$line, array_keys($lines), $lines));
    }

    /**
     * The schema: one property per item of the policy, each a value (text
     * or a list of texts, null when nothing gives it), where it came
     * from, and the quotes it rests on when that is the document.
     *
     * @param  list<string>  $items
     * @return array<string, mixed>
     */
    public static function schema(array $items): array
    {
        $item = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => ['string', 'array', 'null'], 'items' => ['type' => 'string']],
                'source' => ['type' => 'string', 'enum' => self::SOURCES],
                'quotes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'line_start' => ['type' => 'integer'],
                            'line_end' => ['type' => 'integer'],
                            'quote' => ['type' => 'string'],
                        ],
                        'required' => ['line_start', 'line_end', 'quote'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['value', 'source', 'quotes'],
            'additionalProperties' => false,
        ];

        $properties = array_fill_keys($items, $item);

        return ['type' => 'object', 'properties' => $properties, 'required' => array_keys($properties), 'additionalProperties' => false];
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

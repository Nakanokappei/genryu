<?php

namespace App\Actions;

use App\Models\Article;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The first half of a top image (トップ画像): given the image layer of the
 * editorial policy, an article, the material it was written from and the
 * style of the hour it goes out at, a model chooses the one scene the
 * image shows and describes it for an illustrator, in English. The call
 * goes to the Responses API like the others, the policy cached. It only
 * proposes; App\Jobs\MakeImage hands the scene to App\Actions\DrawImage.
 */
class ProposeScene
{
    private const ENDPOINT = 'https://api.openai.com/v1/responses';

    /** What the model is told after the cached policy. Shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'The article below goes out at the hour whose style follows it. Choose the one scene of its top image, following the policy above, fitting the theme of that hour, and describe it in `scene`.';

    /**
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, array $material, string $style): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, self::request($policy, $model, $article, $material, $style))
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
                'output_tokens' => self::count($response->json('usage.output_tokens')),
                'latency_ms' => $latency,
            ],
        ];
    }

    /**
     * The request: the policy first, cached; the instruction; then the
     * article, the parts of the material that say what could change, and
     * the style of the hour last.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, Article $article, array $material, string $style): array
    {
        // The scene comes from the world the article says becomes possible, so the material's inference goes along; its figures do not, so that nobody's drawing is copied.
        $parts = array_intersect_key($material, array_flip(['angle', 'change', 'after', 'future_society', 'winners']));

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
                ['role' => 'developer', 'content' => self::INSTRUCTIONS],
                ['role' => 'user', 'content' => "Article:\n\n# {$article->title}\n\n{$article->body}\n\nMaterial (JSON):\n".json_encode($parts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\nThe style of the hour it goes out at:\n{$style}"],
            ],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => 'scene',
                    'strict' => true,
                    'schema' => [
                        'type' => 'object',
                        'properties' => ['scene' => ['type' => 'string']],
                        'required' => ['scene'],
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

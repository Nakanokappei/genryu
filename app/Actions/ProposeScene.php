<?php

namespace App\Actions;

use App\Models\Article;
use App\OpenAi\Responses;

/**
 * Chooses and describes the scene of a トップ画像 (UI "Top image") from an
 * article, its material and the style of the hour; DrawImage draws it.
 */
class ProposeScene
{
    /** Fixed instruction sent after the cached policy; shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'The article below goes out at the hour whose style follows it. Choose the one scene of its top image, following the policy above, fitting the theme of that hour, and describe it in `scene`.';

    /**
     * Sends the request and returns the answer and usage.
     *
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, array $material, string $style): array
    {
        return Responses::send(self::request($policy, $model, $article, $material, $style));
    }

    /**
     * The request: cached policy, instruction, then article, material parts and style.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, Article $article, array $material, string $style): array
    {
        // Only the parts about what becomes possible; not the figures, so no drawing is copied.
        $parts = array_intersect_key($material, array_flip(['angle', 'change', 'after', 'future_society', 'winners']));

        return Responses::request($model, [
            Responses::policy($policy),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS],
            ['role' => 'user', 'content' => "Article:\n\n# {$article->headline}\n\n{$article->body}\n\nMaterial (JSON):\n".json_encode($parts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\nThe style of the hour it goes out at:\n{$style}"],
        ], 'scene', [
            'type' => 'object',
            'properties' => ['scene' => ['type' => 'string']],
            'required' => ['scene'],
            'additionalProperties' => false,
        ]);
    }
}

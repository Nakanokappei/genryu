<?php

namespace App\Actions;

use App\Models\Article;
use App\OpenAi\Responses;

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
    /** What the model is told after the cached policy. Shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'The article below goes out at the hour whose style follows it. Choose the one scene of its top image, following the policy above, fitting the theme of that hour, and describe it in `scene`.';

    /**
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, array $material, string $style): array
    {
        return Responses::send(self::request($policy, $model, $article, $material, $style));
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

        return Responses::request($model, [
            Responses::policy($policy),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS],
            ['role' => 'user', 'content' => "Article:\n\n# {$article->title}\n\n{$article->body}\n\nMaterial (JSON):\n".json_encode($parts, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\nThe style of the hour it goes out at:\n{$style}"],
        ], 'scene', [
            'type' => 'object',
            'properties' => ['scene' => ['type' => 'string']],
            'required' => ['scene'],
            'additionalProperties' => false,
        ]);
    }
}

<?php

namespace App\Actions;

use App\Models\Article;
use App\OpenAi\Responses;

/**
 * The judge of 品質チェック (UI: "Quality check"): given the quality layer
 * of the editorial policy, which carries the media's standards and the
 * rubric, an article as written and the material it was written from, a
 * model scores the article out of 100 and says where the points were
 * lost. The call goes to the Responses API like the others: the policy
 * as the developer message with an explicit prompt-cache breakpoint,
 * then what changes per article. It only proposes; App\Jobs\CheckQuality
 * keeps the score.
 */
class ScoreQuality
{
    /** What the model is told after the cached policy. Shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'Score the article below against the standards and the rubric above, out of 100. The material after it is what the article was written from: check every fact against it. Give the total in `score` and, in the language of the article, where the points were lost in `reason`.';

    /**
     * @param  array<string, mixed>  $material
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, Article $article, array $material): array
    {
        return Responses::send(self::request($policy, $model, $article, $material));
    }

    /**
     * The request: the policy first, as the developer message, with the
     * cache breakpoint on it; the instruction after it; the article with
     * its headline, then the material, last; the answer constrained to a
     * score and a reason.
     *
     * @param  array<string, mixed>  $material
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, Article $article, array $material): array
    {
        return Responses::request($model, [
            Responses::policy($policy),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS],
            ['role' => 'user', 'content' => "Article:\n\n# {$article->headline}\n\n{$article->body}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ], 'quality', [
            'type' => 'object',
            'properties' => [
                'score' => ['type' => 'integer'],
                'reason' => ['type' => 'string'],
            ],
            'required' => ['score', 'reason'],
            'additionalProperties' => false,
        ]);
    }
}

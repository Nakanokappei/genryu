<?php

namespace App\Actions;

use App\Models\LanguageSetting;
use App\OpenAi\Responses;

/**
 * The writer of 見出し (UI "Headline"): writes a headline from a material,
 * in its language, in four steps; a retry is given the last review and
 * the headlines tried. App\Jobs\RefineHeadline scores and keeps the best.
 */
class ProposeHeadline
{
    /** Fixed instruction sent after the cached policy; shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'The material below was drawn from one primary-source document, and no article has been written from it yet. Write the headline of that article, following the policy above, in the language the material is written in; the article will be written under it. Use only what the material says. When headlines were already tried, the review of the last one says what to change: change that, do not tune the wording of a headline that failed on what it says, and never reuse a headline already tried.';

    /**
     * Sends the request and returns the answer and usage.
     *
     * @param  array<string, mixed>  $material
     * @param  array<string, mixed>|null  $review  what the judge said of the last headline, or null for the first
     * @param  list<string>  $tried  the headlines already scored
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, array $material, ?array $review = null, array $tried = [], ?string $language = null): array
    {
        return Responses::send(self::request($policy, $model, $material, $review, $tried, $language));
    }

    /**
     * The request: cached policy, language prompts, instruction and rubric,
     * then any earlier attempts and the material; the schema orders the four steps.
     *
     * @param  array<string, mixed>  $material
     * @param  array<string, mixed>|null  $review
     * @param  list<string>  $tried
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, array $material, ?array $review, array $tried, ?string $language = null): array
    {
        $input = '';

        // A retry: headlines tried, the review and the shortfall.
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

        return Responses::request($model, [
            Responses::policy($policy),
            // 言語別の追加プロンプト (UI "Additional prompt per language").
            ...LanguageSetting::messages($language),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS."\n\n".ScoreHeadline::rubric()],
            ['role' => 'user', 'content' => $input],
        ], 'headline', [
            'type' => 'object',
            'properties' => [
                'topic_word' => ['type' => 'string'],
                'title_draft' => ['type' => 'string'],
                'assumption' => ['type' => 'string'],
                'headline' => ['type' => 'string'],
            ],
            'required' => ['topic_word', 'title_draft', 'assumption', 'headline'],
            'additionalProperties' => false,
        ]);
    }
}

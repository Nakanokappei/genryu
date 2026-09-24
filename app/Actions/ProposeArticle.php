<?php

namespace App\Actions;

use App\Enums\Language;
use App\Models\Article;
use App\Models\LanguageSetting;
use App\OpenAi\Responses;

/**
 * The agent that writes a 記事 (UI "Article") body, lead and figure choices
 * from a material under the settled headline, in the material's language.
 * App\Jobs\GenerateArticle validates and saves it.
 */
class ProposeArticle
{
    /** Fixed instruction sent after the cached policy; shown on the screen under the prompt. */
    public const INSTRUCTIONS = 'The material below was drawn from one primary-source document, and the headline of its article has already been settled. Write the body of the article under that headline, following the policy above, in the language the material is written in. Use only what the material says; never invent facts, figures or quotes that are not in it. Then, having written the body, write its lead in `lead`: one short paragraph that sums up the whole article — what changed and why it matters to the reader — so that someone who reads only the headline and the lead knows what the article says. The lead stands above the body and reads on its own: never begin it with a conjunction or refer back to anything, do not repeat the headline word for word, and say nothing the body does not say. The body itself starts with the opening, not with the lead. Name the language in `language`. When the source has numbered figures, quote at least one and at most two of them in `figures`, by number: the ones that best show what the body talks about, each with the section whose text it illustrates — opening (before the first ## heading), background (the first ## section), technology (the second) or outlook (the third). Leave `figures` empty only when the source has no figures.';

    /**
     * Sends the request and returns the answer and usage.
     *
     * @param  array<string, mixed>  $material
     * @param  array{body: string, problem: string}|null  $revision  the previous body and its problems, for a rewrite
     * @return array{json: array<string, mixed>, usage: array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $policy, string $model, array $material, string $headline, string $documentTitle, string $url, ?array $revision = null, ?string $language = null): array
    {
        return Responses::send(self::request($policy, $model, $material, $headline, $documentTitle, $url, $revision, $language));
    }

    /**
     * The request: cached policy, language prompts, instruction, then headline and material; a rewrite adds the previous body.
     *
     * @param  array<string, mixed>  $material
     * @param  array{body: string, problem: string}|null  $revision
     * @return array<string, mixed>
     */
    public static function request(string $policy, string $model, array $material, string $headline, string $documentTitle, string $url, ?array $revision = null, ?string $language = null): array
    {
        $input = [
            Responses::policy($policy),
            // 言語別の追加プロンプト (UI "Additional prompt per language").
            ...LanguageSetting::messages($language),
            ['role' => 'developer', 'content' => self::INSTRUCTIONS],
            ['role' => 'user', 'content' => "Headline: {$headline}\n\nSource document: {$documentTitle}\nURL: {$url}\n\nMaterial (JSON):\n".json_encode($material, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).self::figureList($material)],
        ];

        // Rewrite: the previous body and its problems.
        if ($revision !== null) {
            $input[] = ['role' => 'user', 'content' => "The body you wrote:\n\n{$revision['body']}\n\nWhat is wrong with it:\n{$revision['problem']}\n\nWrite it again, fixing that and keeping everything else."];
        }

        return Responses::request($model, $input, 'article', [
            'type' => 'object',
            'properties' => [
                'body' => ['type' => 'string'],
                // After body, so it sums up the body just written.
                'lead' => ['type' => 'string'],
                // The language written in, to know what to translate into.
                'language' => ['type' => 'string', 'enum' => Language::codes()],
                // Figures to quote, by number, with their section.
                'figures' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['figure' => ['type' => 'integer'], 'section' => ['type' => 'string', 'enum' => Article::FIGURE_SECTIONS]],
                    'required' => ['figure', 'section'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['body', 'lead', 'language', 'figures'],
            'additionalProperties' => false,
        ]);
    }

    /**
     * The material's figures numbered from 1, or '' when there are none.
     *
     * @param  array<string, mixed>  $material
     */
    private static function figureList(array $material): string
    {
        $figures = array_values((array) ($material['figures'] ?? []));

        if ($figures === []) {
            return '';
        }

        $lines = array_map(fn (array $figure, int $i): string => ($i + 1).'. '.trim(($figure['alt'] ?? '').' '.($figure['caption'] ?? '')), $figures, array_keys($figures));

        return "\n\nFigures of the source (quote by number):\n".implode("\n", $lines);
    }
}

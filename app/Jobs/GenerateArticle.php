<?php

namespace App\Jobs;

use App\Actions\ProposeArticle;
use App\Actions\ValidateArticle;
use App\Enums\Language;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\LanguageSetting;
use App\Models\Material;
use App\Models\Prompt;
use App\OpenAi\Usage;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 記事を生成 (UI: "Generate article", stage 2.4 of docs/HANDOVER.md): an
 * article is made in three steps, each in the background. The headline
 * comes first, from the material (App\Jobs\RefineHeadline); this job then
 * has the agent write the body under that headline per the article
 * generation layer of the editorial policy, checks that a body came back,
 * and keeps it; the translations and the quality check follow. The article pins the prompt
 * versions and the models it was written with and keeps the usage of the
 * body's call, as a screening and a material do. The outcome lands on the
 * article (status 生成中 / 下書き / 失敗) so the screens can show it.
 *
 * The shape and the length of the body are checked here, not trusted
 * to the model (App\Actions\ValidateArticle, 2026-09-23: an opening that
 * swallowed 承, a missing sources section): a body with problems is
 * written again with them in hand, up to MAX_REWRITES times, and the one
 * with the fewest problems, then the nearest length, is kept. Nothing
 * waits for a person, so a body that still has problems is kept and
 * they are shown in its status message.
 *
 * The lead comes back in the same answer, after the body (the schema
 * names it second, and a model writes the properties in that order), as
 * a summary of the body it has just written (2026-09-25: written before
 * the body, it ran into the opening, which began with "しかし"); it is
 * put above the body with Article::LEAD_SEPARATOR between them.
 */
class GenerateArticle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** How many times a body with problems is written again. */
    public const MAX_REWRITES = 2;

    public function __construct(public Article $article) {}

    /**
     * Queue the writing of a material's article: the row appears at once
     * as 生成中, whether it is new or being written again, and the
     * headline is written first; this job follows it. This is the
     * original — the one written from the material, in the language of
     * the primary source — so it is the material's article that has no
     * article behind it; the translations are queued once it is written.
     */
    public static function queueFor(Material $material): Article
    {
        $article = Article::query()->updateOrCreate(
            ['material_id' => $material->id, 'translated_from_id' => null],
            [
                'status' => 'generating',
                'status_message' => null,
                'prompt_id' => Prompt::forLayer('article')->id,
                'model' => EditorialPolicy::modelFor('article'),
            ],
        );

        return RefineHeadline::queueFor($article);
    }

    public function handle(ProposeArticle $propose, ValidateArticle $validate): void
    {
        $article = $this->article;
        $material = $article->material;

        try {
            if ($material === null || $material->status !== 'extracted' || $material->data === null) {
                throw new RuntimeException(__('The material has not been extracted yet.'));
            }

            if (trim((string) $article->title) === '') {
                throw new RuntimeException(__('The headline has not been written yet.'));
            }

            // The policy read is the version pinned when the job was queued, not whatever the screen holds by now.
            $policy = Prompt::textOf($article->prompt, 'article');

            $document = $material->document;
            $model = (string) $article->model;
            // A first write, or a rewrite of a body with what is wrong with it.
            $write = fn (?string $previous = null, string $problems = ''): array => $propose($policy, $model, (array) $material->data, (string) $article->title, $document->title, $document->url, $previous === null ? null : ['body' => $previous, 'problem' => $problems], $document->language);
            // A written body with what the checks found in it, and how to rank it: fewer problems first, then a length nearer the range.
            $check = function (array $result) use ($validate, $article, $document): array {
                $body = trim((string) ($result['json']['body'] ?? ''));
                $language = $result['json']['language'] ?? null;
                $lead = trim((string) ($result['json']['lead'] ?? ''));

                return [
                    'result' => $result,
                    'body' => $body,
                    'lead' => $lead,
                    'problems' => [...$validate($body, $language, (string) $article->title, $document->url), ...($lead === '' ? ['The lead is missing: after the body, sum it up in `lead`.'] : [])],
                    'off' => abs(ValidateArticle::lengthOf($body, $language)['off']),
                ];
            };
            $written = $check($write());

            if ($written['body'] === '') {
                throw new RuntimeException(__('The agent did not return a body.'));
            }

            $best = $written;
            $usages = [$written['result']['usage']];

            // A body with problems is written again with them in hand, from the last one written.
            for ($rewrite = 1; $rewrite <= self::MAX_REWRITES && $written['problems'] !== []; $rewrite++) {
                $written = $check($write($written['body'], implode("\n", array_map(fn (string $problem): string => "- {$problem}", $written['problems']))));
                $usages[] = $written['result']['usage'];

                if ($written['body'] !== '' && [count($written['problems']), $written['off']] < [count($best['problems']), $best['off']]) {
                    $best = $written;
                }
            }

            $result = $best['result'];
            $body = $best['body'];
            // Every call is paid for, so every call is counted.
            $result['usage'] = Usage::sum($usages);

            $article->update([
                // The lead the writer summed the body up in, above it; none if it left the lead out.
                'body' => Article::separateBlocks($best['lead'] !== '' ? Article::withLead($best['lead'], $body) : $body),
                'figures' => self::figures((array) ($result['json']['figures'] ?? []), $material->figures()),
                // The language the agent says it wrote in, which is the material's and so the primary source's.
                'language' => in_array($result['json']['language'] ?? null, Language::codes(), true) ? $result['json']['language'] : null,
                'status' => 'draft',
                'status_message' => __('Generated by :model.', ['model' => $model]).($best['problems'] === [] ? '' : ' '.__('The article did not pass the checks: :errors', ['errors' => implode(' ', $best['problems'])])),
                ...$result['usage'],
                'estimated_total_cost' => Usage::estimatedCost($model, $result['usage'])['estimated_total_cost'],
            ]);
        } catch (Throwable $exception) {
            $article->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);

            return;
        }

        // Nothing waits for a person: the languages we publish in follow the written article, and 編成 scores it (品質チェック).
        foreach (LanguageSetting::translationTargets($article->refresh()->language) as $language) {
            TranslateArticle::queueFor($article, $language);
        }

        CheckQuality::queueFor($article);
    }

    /**
     * The figures the writer chose to quote, checked: a number the
     * source's figures have, a section an article has, each figure once,
     * at most Article::MAX_FIGURES. The URL, the alt text and the caption
     * are taken from the material, never from the model's answer. A source
     * with figures always has one in its article (decided 2026-09-25):
     * when the writer chose none that holds, the first stands in the
     * section on the new technology.
     *
     * @param  array<int, mixed>  $chosen
     * @param  list<array<string, mixed>>  $figures
     * @return list<array{url: string, alt: string, caption: ?string, section: string}>
     */
    public static function figures(array $chosen, array $figures): array
    {
        $kept = [];

        foreach ($chosen as $choice) {
            $number = is_array($choice) && is_int($choice['figure'] ?? null) ? $choice['figure'] : 0;
            $section = is_array($choice) ? ($choice['section'] ?? null) : null;
            $figure = $figures[$number - 1] ?? null;

            if ($figure === null || ! in_array($section, Article::FIGURE_SECTIONS, true) || isset($kept[$number]) || count($kept) >= Article::MAX_FIGURES) {
                continue;
            }

            $kept[$number] = self::figure($figure, (string) $section);
        }

        if ($kept === [] && $figures !== []) {
            $kept[1] = self::figure($figures[0], 'technology');
        }

        return array_values($kept);
    }

    /**
     * A figure of the material as the article keeps it.
     *
     * @param  array<string, mixed>  $figure
     * @return array{url: string, alt: string, caption: ?string, section: string}
     */
    private static function figure(array $figure, string $section): array
    {
        return ['url' => (string) $figure['url'], 'alt' => (string) ($figure['alt'] ?? ''), 'caption' => isset($figure['caption']) ? (string) $figure['caption'] : null, 'section' => $section];
    }
}

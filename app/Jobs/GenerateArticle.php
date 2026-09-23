<?php

namespace App\Jobs;

use App\Actions\ProposeArticle;
use App\Actions\ValidateArticle;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Material;
use App\Models\Prompt;
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
                'prompt_id' => Prompt::current('article', EditorialPolicy::bodyFor('article'))->id,
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
            $policy = $article->prompt !== null ? $article->prompt->text : '';

            if (trim($policy) === '') {
                throw new RuntimeException(__('The article generation layer of the editorial policy is empty.'));
            }

            $document = $material->document;
            $model = (string) $article->model;
            // A first write, or a rewrite of a body with what is wrong with it.
            $write = fn (?string $previous = null, string $problems = ''): array => $propose($policy, $model, (array) $material->data, (string) $article->title, $document->title, $document->url, $previous === null ? null : ['body' => $previous, 'problem' => $problems]);
            // A written body with what the checks found in it, and how to rank it: fewer problems first, then a length nearer the range.
            $check = function (array $result) use ($validate, $article, $document): array {
                $body = trim((string) ($result['json']['body'] ?? ''));
                $language = $result['json']['language'] ?? null;

                return [
                    'result' => $result,
                    'body' => $body,
                    'problems' => $validate($body, $language, (string) $article->title, $document->url),
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
            $result['usage'] = self::sumUsage($usages);

            $article->update([
                'body' => Article::separateBlocks($body),
                // The language the agent says it wrote in, which is the material's and so the primary source's.
                'language' => in_array($result['json']['language'] ?? null, Article::SOURCE_LANGUAGES, true) ? $result['json']['language'] : null,
                'status' => 'draft',
                'status_message' => __('Generated by :model.', ['model' => $model]).($best['problems'] === [] ? '' : ' '.__('The article did not pass the checks: :errors', ['errors' => implode(' ', $best['problems'])])),
                ...$result['usage'],
                'estimated_total_cost' => ScreenDocument::estimatedCost($model, $result['usage'])['estimated_total_cost'],
            ]);
        } catch (Throwable $exception) {
            $article->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);

            return;
        }

        // Nothing waits for a person: the languages we publish in follow the written article, and 編成 scores it (品質チェック).
        foreach ($article->refresh()->translationLanguages() as $language) {
            TranslateArticle::queueFor($article, $language);
        }

        CheckQuality::queueFor($article);
    }

    /**
     * The usage of several calls added up, key by key; a figure no call
     * reported stays unknown.
     *
     * @param  list<array<string, ?int>>  $usages
     * @return array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: ?int}
     */
    private static function sumUsage(array $usages): array
    {
        $sum = function (string $key) use ($usages): ?int {
            $values = array_filter(array_column($usages, $key), fn ($value) => $value !== null);

            return $values === [] ? null : (int) array_sum($values);
        };

        return ['input_tokens' => $sum('input_tokens'), 'cached_tokens' => $sum('cached_tokens'), 'cache_write_tokens' => $sum('cache_write_tokens'), 'output_tokens' => $sum('output_tokens'), 'latency_ms' => $sum('latency_ms')];
    }
}

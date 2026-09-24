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
 * 記事を生成 (UI: "Generate article"): write the original article's body
 * and lead under its settled headline, check and rewrite it, then queue
 * the translations and the quality check.
 */
class GenerateArticle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** How many times a body with problems is written again. */
    public const MAX_REWRITES = 2;

    public function __construct(public Article $article) {}

    /** Create or reset the material's original article (生成中) and queue its headline first. */
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

    /** Write the body, rewrite it while it has problems, keep the best, and queue what follows. */
    public function handle(ProposeArticle $propose, ValidateArticle $validate): void
    {
        $article = $this->article;
        $material = $article->material;

        try {
            // An extracted material and a headline are needed.
            if ($material === null || $material->status !== 'extracted' || $material->parts === null) {
                throw new RuntimeException(__('The material has not been extracted yet.'));
            }

            if (trim((string) $article->headline) === '') {
                throw new RuntimeException(__('The headline has not been written yet.'));
            }

            // The pinned policy version.
            $policy = Prompt::textOf($article->prompt, 'article');

            $document = $material->document;
            $model = (string) $article->model;
            // A first write, or a rewrite with the problems.
            $write = fn (?string $previous = null, string $problems = ''): array => $propose($policy, $model, (array) $material->parts, (string) $article->headline, $document->title, $document->url, $previous === null ? null : ['body' => $previous, 'problem' => $problems], $document->language);
            // A written body with its problems and its distance from the length range, for ranking.
            $check = function (array $result) use ($validate, $article, $document): array {
                $body = trim((string) ($result['json']['body'] ?? ''));
                $language = $result['json']['language'] ?? null;
                $lead = trim((string) ($result['json']['lead'] ?? ''));

                return [
                    'result' => $result,
                    'body' => $body,
                    'lead' => $lead,
                    'problems' => [...$validate($body, $language, (string) $article->headline, $document->url), ...($lead === '' ? ['The lead is missing: after the body, sum it up in `lead`.'] : [])],
                    'off' => abs(ValidateArticle::lengthOf($body, $language)['off']),
                ];
            };
            $written = $check($write());

            // No body at all fails the article.
            if ($written['body'] === '') {
                throw new RuntimeException(__('The agent did not return a body.'));
            }

            $best = $written;
            $usages = [$written['result']['usage']];

            // Rewrite from the last body while it has problems.
            for ($rewrite = 1; $rewrite <= self::MAX_REWRITES && $written['problems'] !== []; $rewrite++) {
                $written = $check($write($written['body'], implode("\n", array_map(fn (string $problem): string => "- {$problem}", $written['problems']))));
                $usages[] = $written['result']['usage'];

                // Keep the best: fewer problems, then nearer the length.
                if ($written['body'] !== '' && [count($written['problems']), $written['off']] < [count($best['problems']), $best['off']]) {
                    $best = $written;
                }
            }

            $result = $best['result'];
            $body = $best['body'];
            // The usage of every call.
            $result['usage'] = Usage::sum($usages);

            $article->update([
                // The lead above the body, when there is one.
                'body' => Article::separateBlocks($best['lead'] !== '' ? Article::withLead($best['lead'], $body) : $body),
                'figures' => self::figures((array) ($result['json']['figures'] ?? []), $material->figures()),
                // The language the agent says it wrote in (the primary source's).
                'language' => in_array($result['json']['language'] ?? null, Language::codes(), true) ? $result['json']['language'] : null,
                'status' => 'written',
                'status_message' => __('Generated by :model.', ['model' => $model]).($best['problems'] === [] ? '' : ' '.__('The article did not pass the checks: :errors', ['errors' => implode(' ', $best['problems'])])),
                ...$result['usage'],
                'estimated_total_cost' => Usage::costOfCalls($model, $usages),
            ]);
        } catch (Throwable $exception) {
            $article->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);

            return;
        }

        // Queue the translations and the quality check.
        foreach (LanguageSetting::translationTargets($article->refresh()->language) as $language) {
            TranslateArticle::queueFor($article, $language);
        }

        CheckQuality::queueFor($article);
    }

    /**
     * The valid figures the writer chose, taken from the material; the first
     * figure in the technology section when none was chosen.
     *
     * @param  array<int, mixed>  $chosen
     * @param  list<array<string, mixed>>  $figures
     * @return list<array{url: string, alt: string, caption: ?string, section: string}>
     */
    public static function figures(array $chosen, array $figures): array
    {
        $kept = [];

        // Keep a choice with a known figure and section, each once, up to MAX_FIGURES.
        foreach ($chosen as $choice) {
            $number = is_array($choice) && is_int($choice['figure'] ?? null) ? $choice['figure'] : 0;
            $section = is_array($choice) ? ($choice['section'] ?? null) : null;
            $figure = $figures[$number - 1] ?? null;

            if ($figure === null || ! in_array($section, Article::FIGURE_SECTIONS, true) || isset($kept[$number]) || count($kept) >= Article::MAX_FIGURES) {
                continue;
            }

            $kept[$number] = self::figure($figure, (string) $section);
        }

        // Fall back to the first figure.
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

<?php

namespace App\Jobs;

use App\Actions\ProposeHeadline;
use App\Actions\ScoreHeadline;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 見出し (the headline loop, the first step of an article, stage 2.4): a
 * headline is written from the material in the language of the primary
 * source (App\Actions\ProposeHeadline) and scored against the rubric on
 * that material (App\Actions\ScoreHeadline); when it does not pass,
 * another is written with the review in hand and scored again, up to
 * ATTEMPTS times. The best-scoring headline is kept, whether or not any
 * of them passed — an article is never left without one — and the whole
 * review is kept beside it so a person can see what it scored and why.
 *
 * This is the one loop in the pipeline, and it is bounded: a headline is
 * the hook the whole article rests on, and it is the cheapest thing to
 * write again. Nothing waits for a person: the body is written under the
 * settled headline (App\Jobs\GenerateArticle), and the translations
 * follow the body.
 */
class RefineHeadline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** How many headlines are scored at most: the first one written, and the rewrites after it. */
    public const ATTEMPTS = 3;

    public function __construct(public Article $article) {}

    /**
     * Queue the loop for an article about to be written. The prompt
     * version and the model are pinned now, as everywhere else, so a
     * policy saved while the job waits does not change what it ran with.
     */
    public static function queueFor(Article $article): Article
    {
        $article->update([
            'headline_prompt_id' => Prompt::forLayer('headline')->id,
            'headline_model' => EditorialPolicy::modelFor('headline'),
        ]);

        self::dispatch($article);

        return $article;
    }

    public function handle(ScoreHeadline $score, ProposeHeadline $propose): void
    {
        $article = $this->article;
        $material = $article->material;

        try {
            if ($material === null || $material->status !== 'extracted' || $material->data === null) {
                throw new RuntimeException(__('The material has not been extracted yet.'));
            }

            $policy = Prompt::textOf($article->headlinePrompt, 'headline');

            $data = (array) $material->data;
            // The headline is written in the source's language, with what belongs to that language.
            $language = $material->document->language;
            $model = (string) $article->headline_model;
            $attempts = [];
            $review = null;
            $best = null;

            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                // The first headline comes from the material alone; each one after it also from the review of the last.
                $headline = trim((string) ($propose($policy, $model, $data, $review, array_column($attempts, 'headline'), $language)['json']['headline'] ?? ''));

                if ($headline === '') {
                    break;
                }

                $review = ScoreHeadline::review($score($policy, $model, $headline, $data, $language)['json'], $headline);
                $attempts[] = ['headline' => $headline, 'total' => $review['total'], 'passed' => $review['passed'], 'musts_failed' => $review['musts_failed']];

                // The best is the one that passed, else one that failed no must, else the highest total; a tie keeps the earlier.
                $rank = fn (array $review): array => [$review['passed'], $review['musts_failed'] === [], $review['total']];

                if ($best === null || $rank($review) > $rank($best['review'])) {
                    $best = ['headline' => $headline, 'review' => $review];
                }

                if ($review['passed']) {
                    break;
                }
            }

            if ($best === null) {
                throw new RuntimeException(__('The agent did not return a headline.'));
            }

            $article->update([
                'title' => $best['headline'],
                'headline_review' => [...$best['review'], 'attempts' => $attempts],
            ]);
        } catch (Throwable $exception) {
            // There is no body without a headline, so the article fails here.
            $article->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);

            return;
        }

        GenerateArticle::dispatch($article->refresh());
    }
}

<?php

namespace App\Jobs;

use App\Actions\ProposeHeadline;
use App\Actions\ScoreHeadline;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 見出し (the headline loop, stage 2.4): the headline the writer gave the
 * article is scored against the rubric (App\Actions\ScoreHeadline); when
 * it does not pass, another is written with the review in hand
 * (App\Actions\ProposeHeadline) and scored again, up to ATTEMPTS times.
 * The best-scoring headline is kept, whether or not any of them passed —
 * an article is never left without one — and the whole review is kept
 * beside it so a person can see what it scored and why.
 *
 * This is the one loop in the pipeline, and it is bounded: a headline is
 * the hook the whole article rests on, and it is the cheapest thing to
 * write again. Nothing waits for a person: the translations are queued
 * when the headline is settled, so that they translate the final one.
 */
class RefineHeadline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** How many headlines are scored at most: the one the writer gave, and the rewrites after it. */
    public const ATTEMPTS = 3;

    public function __construct(public Article $article) {}

    /**
     * Queue the loop for an article as written. The prompt version and
     * the model are pinned now, as everywhere else, so a policy saved
     * while the job waits does not change what it ran with.
     */
    public static function queueFor(Article $article): Article
    {
        $article->update([
            'headline_prompt_id' => Prompt::current('headline', EditorialPolicy::bodyFor('headline'))->id,
            'headline_model' => EditorialPolicy::modelFor('headline'),
        ]);

        self::dispatch($article);

        return $article;
    }

    public function handle(ScoreHeadline $score, ProposeHeadline $propose): void
    {
        $article = $this->article;

        try {
            if ($article->body === null || $article->title === null) {
                throw new RuntimeException(__('The article has not been written yet.'));
            }

            $policy = $article->headlinePrompt !== null ? $article->headlinePrompt->text : '';

            if (trim($policy) === '') {
                throw new RuntimeException(__('The headline layer of the editorial policy is empty.'));
            }

            $model = (string) $article->headline_model;
            $headline = (string) $article->title;
            $attempts = [];
            $best = null;

            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                $review = ScoreHeadline::review($score($policy, $model, $headline, $article)['json']);
                $attempts[] = ['headline' => $headline, 'total' => $review['total'], 'passed' => $review['passed']];

                // The best is the one that passed, else the highest total; a tie keeps the earlier, which the writer chose.
                if ($best === null || $review['total'] > $best['review']['total']) {
                    $best = ['headline' => $headline, 'review' => $review];
                }

                if ($review['passed'] || $attempt === self::ATTEMPTS) {
                    break;
                }

                $headline = trim((string) ($propose($policy, $model, $article, $review, array_column($attempts, 'headline'))['json']['headline'] ?? ''));

                if ($headline === '') {
                    break;
                }
            }

            $article->update([
                'title' => $best['headline'],
                'headline_review' => [...$best['review'], 'attempts' => $attempts],
            ]);
        } catch (Throwable $exception) {
            // The article stands with the headline it has; only the review is lost.
            $article->update(['headline_review' => ['error' => mb_substr($exception->getMessage(), 0, 500)]]);
        }

        // Nothing waits for a person: the languages we publish in follow the settled headline.
        foreach ($article->refresh()->translationLanguages() as $language) {
            TranslateArticle::queueFor($article, $language);
        }
    }
}

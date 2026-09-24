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
 * 見出し (UI: "Headline"): write and score headlines from the material, up
 * to ATTEMPTS, keep the best with its review, then queue the body.
 */
class RefineHeadline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /** How many headlines are scored at most: the first one written, and the rewrites after it. */
    public const ATTEMPTS = 3;

    public function __construct(public Article $article) {}

    /** Pin the headline prompt and model on the article and queue the loop. */
    public static function queueFor(Article $article): Article
    {
        $article->update([
            'headline_prompt_id' => Prompt::forLayer('headline')->id,
            'headline_model' => EditorialPolicy::modelFor('headline'),
        ]);

        self::dispatch($article);

        return $article;
    }

    /** Write, score and rewrite headlines, keep the best, and queue GenerateArticle. */
    public function handle(ScoreHeadline $score, ProposeHeadline $propose): void
    {
        $article = $this->article;
        $material = $article->material;

        try {
            // An extracted material is needed.
            if ($material === null || $material->status !== 'extracted' || $material->parts === null) {
                throw new RuntimeException(__('The material has not been extracted yet.'));
            }

            $policy = Prompt::textOf($article->headlinePrompt, 'headline');

            $data = (array) $material->parts;
            // The source's language.
            $language = $material->document->language;
            $model = (string) $article->headline_model;
            $attempts = [];
            $review = null;
            $best = null;

            // Write and score until one passes or the attempts run out.
            for ($attempt = 1; $attempt <= self::ATTEMPTS; $attempt++) {
                // After the first, the last review goes along.
                $headline = trim((string) ($propose($policy, $model, $data, $review, array_column($attempts, 'headline'), $language)['json']['headline'] ?? ''));

                // No headline: stop.
                if ($headline === '') {
                    break;
                }

                $review = ScoreHeadline::review($score($policy, $model, $headline, $data, $language)['json'], $headline);
                $attempts[] = ['headline' => $headline, 'total' => $review['total'], 'passed' => $review['passed'], 'musts_failed' => $review['musts_failed']];

                // Rank: passed, then no must failed, then total; a tie keeps the earlier.
                $rank = fn (array $review): array => [$review['passed'], $review['musts_failed'] === [], $review['total']];

                // Keep the best so far.
                if ($best === null || $rank($review) > $rank($best['review'])) {
                    $best = ['headline' => $headline, 'review' => $review];
                }

                // A pass ends the loop.
                if ($review['passed']) {
                    break;
                }
            }

            // Not one headline came back.
            if ($best === null) {
                throw new RuntimeException(__('The agent did not return a headline.'));
            }

            $article->update([
                'headline' => $best['headline'],
                'headline_review' => [...$best['review'], 'attempts' => $attempts],
            ]);
        } catch (Throwable $exception) {
            // No headline, no article.
            $article->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);

            return;
        }

        GenerateArticle::dispatch($article->refresh());
    }
}

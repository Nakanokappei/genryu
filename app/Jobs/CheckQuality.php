<?php

namespace App\Jobs;

use App\Actions\ScoreQuality;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\Models\QualityCheck;
use App\OpenAi\Usage;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 品質チェック (UI: "Quality check"): score an original article against the
 * quality layer of the editorial policy and keep the score and the reason.
 */
class CheckQuality implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public QualityCheck $check) {}

    /** Create the check (チェック中) pinning the prompt and model, and queue it. */
    public static function queueFor(Article $article): QualityCheck
    {
        $check = $article->qualityChecks()->create([
            'prompt_id' => Prompt::forLayer('quality')->id,
            'model' => EditorialPolicy::modelFor('quality'),
            'status' => 'checking',
        ]);

        self::dispatch($check);

        return $check;
    }

    /** Score the article and record the result or the failure on the check. */
    public function handle(ScoreQuality $score): void
    {
        $check = $this->check;
        $article = $check->article;

        try {
            // Only a written original is checked.
            if ($article->translated_from_id !== null || $article->body === null || $article->status !== 'written') {
                throw new RuntimeException(__('Only a written original article is checked.'));
            }

            $policy = Prompt::textOf($check->prompt, 'quality');

            $model = (string) $check->model;
            $result = $score($policy, $model, $article, (array) $article->material?->parts);

            // No score, no check.
            if (! is_numeric($result['json']['score'] ?? null)) {
                throw new RuntimeException(__('The agent did not return a score.'));
            }

            $check->update([
                // Clamped to the rubric's 0-100.
                'score' => max(0, min(100, (int) $result['json']['score'])),
                'reason' => trim((string) ($result['json']['reason'] ?? '')),
                'status' => 'checked',
                'status_message' => __('Checked by :model.', ['model' => $model]),
                ...$result['usage'],
                'estimated_total_cost' => Usage::estimatedCost($model, $result['usage'])['estimated_total_cost'],
            ]);
        } catch (Throwable $exception) {
            $check->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);
        }
    }
}

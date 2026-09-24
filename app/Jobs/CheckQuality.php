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
 * 品質チェック (UI: "Quality check", the first stage of 編成 / Production):
 * in the background, have the judge score an article against the quality
 * layer of the editorial policy and keep the score and the reason as a
 * new check. Only an original is checked — a translation says what its
 * original says. Queued as soon as an article is written, and from the
 * screen; nothing waits for a person. The outcome lands on the check
 * (status チェック中 / チェック済み / 失敗) so the screen can show it.
 */
class CheckQuality implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public QualityCheck $check) {}

    /**
     * Queue a check of an article: the row appears at once as チェック中,
     * pinning the prompt version and the model, as every other stage does.
     */
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

    public function handle(ScoreQuality $score): void
    {
        $check = $this->check;
        $article = $check->article;

        try {
            if ($article->translated_from_id !== null || $article->body === null || ! in_array($article->status, ['draft', 'published'], true)) {
                throw new RuntimeException(__('Only a written original article is checked.'));
            }

            $policy = Prompt::textOf($check->prompt, 'quality');

            $model = (string) $check->model;
            $result = $score($policy, $model, $article, (array) $article->material?->data);

            if (! is_numeric($result['json']['score'] ?? null)) {
                throw new RuntimeException(__('The agent did not return a score.'));
            }

            $check->update([
                // The rubric is out of 100, whatever the model adds up to.
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

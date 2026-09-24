<?php

namespace App\Jobs;

use App\Actions\ProposeTranslation;
use App\Actions\ScheduleArticles;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\OpenAi\Usage;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 翻訳 (UI: "Translation"): translate an original article into one
 * language, with the source and the material as context.
 */
class TranslateArticle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Article $article) {}

    /** Create or reset the translation (生成中) pinning prompt and model, and queue it. */
    public static function queueFor(Article $original, string $language): Article
    {
        $translation = Article::query()->updateOrCreate(
            ['material_id' => $original->material_id, 'language' => $language],
            [
                'translated_from_id' => $original->id,
                'status' => 'generating',
                'status_message' => null,
                'prompt_id' => Prompt::forLayer('translation')->id,
                'model' => EditorialPolicy::modelFor('translation'),
            ],
        );

        self::dispatch($translation);

        return $translation;
    }

    /** Translate the original and keep the headline, body and slot; a failure lands on the translation. */
    public function handle(ProposeTranslation $propose): void
    {
        $translation = $this->article;

        try {
            $original = $translation->translatedFrom;

            // The original must have a body.
            if ($original === null || $original->body === null) {
                throw new RuntimeException(__('The article has not been written yet.'));
            }

            // The pinned policy version.
            $policy = Prompt::textOf($translation->prompt, 'translation');

            $document = $original->material?->document;
            $model = (string) $translation->model;
            $result = $propose($policy, $model, $original, (string) $translation->language, (array) $original->material?->parts, (string) $document?->title, (string) $document?->url);
            $title = trim((string) ($result['json']['title'] ?? ''));
            $body = trim((string) ($result['json']['body'] ?? ''));

            // Both a title and a body are needed.
            if ($title === '' || $body === '') {
                throw new RuntimeException(__('The agent did not return a title and a body.'));
            }

            $translation->update([
                'headline' => $title,
                'body' => Article::separateBlocks($body),
                // The original's slot in this language's zone, when it publishes.
                'scheduled_at' => $original->scheduled_at === null || ! $translation->isPublishable() ? $translation->scheduled_at : ScheduleArticles::timeFor($original, (string) $translation->language),
                'status' => 'written',
                'status_message' => __('Translated by :model.', ['model' => $model]),
                ...$result['usage'],
                'estimated_total_cost' => Usage::estimatedCost($model, $result['usage'])['estimated_total_cost'],
            ]);
        } catch (Throwable $exception) {
            $translation->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);
        }
    }
}

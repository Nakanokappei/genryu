<?php

namespace App\Jobs;

use App\Actions\ProposeTranslation;
use App\Actions\ScheduleArticles;
use App\Models\Article;
use App\Models\EditorialPolicy;
use App\Models\Prompt;
use App\OpenAi\Usage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * 翻訳 (stage 2.4 of docs/HANDOVER.md, the other languages): in the
 * background, have the agent render an article in one of the languages
 * we publish in. The article is translated, never written again from the
 * material, so that what the reporter found in the primary source
 * survives into every language; the source and the material go along as
 * context, because a translator without them mistranslates the terms.
 * The translation pins the prompt version and the model it ran with and
 * keeps the usage, as the original does. The outcome lands on the
 * translation (status 生成中 / 下書き / 失敗) so the screens can show it.
 */
class TranslateArticle implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public Article $article) {}

    /**
     * Queue the translation of an article into one language: the row
     * appears at once as 生成中, whether it is new or being translated
     * again, pinned to the prompt as it is now.
     */
    public static function queueFor(Article $original, string $language): Article
    {
        $translation = Article::query()->updateOrCreate(
            ['material_id' => $original->material_id, 'language' => $language],
            [
                'translated_from_id' => $original->id,
                'status' => 'generating',
                'status_message' => null,
                'prompt_id' => Prompt::current('translation', EditorialPolicy::bodyFor('translation'))->id,
                'model' => EditorialPolicy::modelFor('translation'),
            ],
        );

        self::dispatch($translation);

        return $translation;
    }

    public function handle(ProposeTranslation $propose): void
    {
        $translation = $this->article;

        try {
            $original = $translation->translatedFrom;

            if ($original === null || $original->body === null) {
                throw new RuntimeException(__('The article has not been written yet.'));
            }

            // The policy read is the version pinned when the job was queued, not whatever the screen holds by now.
            $policy = $translation->prompt !== null ? $translation->prompt->text : '';

            if (trim($policy) === '') {
                throw new RuntimeException(__('The translation layer of the editorial policy is empty.'));
            }

            $document = $original->material?->document;
            $model = (string) $translation->model;
            $result = $propose($policy, $model, $original, (string) $translation->language, (array) $original->material?->data, (string) $document?->title, (string) $document?->url);
            $title = trim((string) ($result['json']['title'] ?? ''));
            $body = trim((string) ($result['json']['body'] ?? ''));

            if ($title === '' || $body === '') {
                throw new RuntimeException(__('The agent did not return a title and a body.'));
            }

            $translation->update([
                'title' => $title,
                'body' => Article::separateBlocks($body),
                // Written after its original was scheduled, it goes out in the original's slot, in its own zone.
                'scheduled_at' => $original->scheduled_at === null || ! $translation->isPublishable() ? $translation->scheduled_at : ScheduleArticles::timeFor($original, (string) $translation->language),
                'status' => 'draft',
                'status_message' => __('Translated by :model.', ['model' => $model]),
                ...$result['usage'],
                'estimated_total_cost' => Usage::estimatedCost($model, $result['usage'])['estimated_total_cost'],
            ]);
        } catch (Throwable $exception) {
            $translation->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }
}

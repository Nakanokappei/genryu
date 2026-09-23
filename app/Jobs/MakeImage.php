<?php

namespace App\Jobs;

use App\Actions\DrawImage;
use App\Actions\ProposeScene;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\EditorialPolicy;
use App\Models\ImageStyle;
use App\Models\Prompt;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * トップ画像 (UI: "Top image", 編成 › 画像): in the background, a scheduled
 * article gets its top image in two steps — the writer chooses the scene
 * from the article (App\Actions\ProposeScene), the image model draws it
 * in the style of the time band the article's slot falls in
 * (App\Actions\DrawImage, ImageStyle). The image is kept on the local
 * disk and shared by every language version, which go out at the same
 * local time; the article records the time it was made for, so a slot
 * moved by the schedule sends it back to 画像作成中. The outcome lands on
 * the drawing (作成中 / 作成済み / 失敗).
 */
class MakeImage implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ArticleImage $image) {}

    /**
     * Queue a drawing for a scheduled original: the row appears at once as
     * 作成中, pinning the prompt version, both models, and the time and
     * band it is made for.
     */
    public static function queueFor(Article $article): ArticleImage
    {
        $time = (string) $article->scheduledLocal()?->format('H:i');

        $image = $article->images()->create([
            'prompt_id' => Prompt::current('image', EditorialPolicy::bodyFor('image'))->id,
            'model' => EditorialPolicy::modelFor('image'),
            'image_model' => EditorialPolicy::imageModel(),
            'time' => $time,
            'band' => ImageStyle::bandFor($time),
            'status' => 'making',
        ]);

        self::dispatch($image);

        return $image;
    }

    public function handle(ProposeScene $propose, DrawImage $draw): void
    {
        $image = $this->image;
        $article = $image->article;

        try {
            if ($article->translated_from_id !== null || $article->body === null || $article->scheduled_at === null) {
                throw new RuntimeException(__('Only a scheduled original article gets a top image.'));
            }

            $policy = $image->prompt !== null ? $image->prompt->text : '';

            if (trim($policy) === '') {
                throw new RuntimeException(__('The image layer of the editorial policy is empty.'));
            }

            $style = ImageStyle::bands()[$image->band]['style'] ?? '';
            $scene = $propose($policy, (string) $image->model, $article, (array) $article->material?->data, $style);
            $sceneText = trim((string) ($scene['json']['scene'] ?? ''));

            if ($sceneText === '') {
                throw new RuntimeException(__('The agent did not return a scene.'));
            }

            $prompt = DrawImage::prompt($sceneText, $style);
            $drawn = $draw((string) $image->image_model, $prompt);
            $path = "images/{$article->id}/{$image->id}.jpg";
            Storage::disk('local')->put($path, $drawn['bytes']);

            $image->update([
                'status' => 'made',
                'status_message' => __('Drawn by :model.', ['model' => $image->image_model]),
                'scene' => $sceneText,
                'image_prompt' => $prompt,
                'path' => $path,
                'input_tokens' => $scene['usage']['input_tokens'],
                'output_tokens' => $scene['usage']['output_tokens'],
                'image_input_tokens' => $drawn['usage']['input_tokens'],
                'image_output_tokens' => $drawn['usage']['output_tokens'],
                'latency_ms' => $scene['usage']['latency_ms'] + $drawn['usage']['latency_ms'],
                'estimated_total_cost' => self::cost($image, $scene['usage'], $drawn['usage']),
            ]);

            // The article carries its image, and the time of day it was made for.
            $article->update(['image_path' => $path, 'image_time' => $image->time]);
        } catch (Throwable $exception) {
            $image->update(['status' => 'failed', 'status_message' => mb_substr($exception->getMessage(), 0, 1000)]);
        }
    }

    /**
     * What the two calls cost in USD: the scene at the writer's prices, the
     * drawing at the image model's; unknown when either price is.
     *
     * @param  array{input_tokens: ?int, output_tokens: ?int, latency_ms: int}  $scene
     * @param  array{input_tokens: ?int, output_tokens: ?int, latency_ms: int}  $drawn
     */
    private static function cost(ArticleImage $image, array $scene, array $drawn): ?float
    {
        $writing = ScreenDocument::estimatedCost((string) $image->model, ['input_tokens' => $scene['input_tokens'], 'cached_tokens' => 0, 'output_tokens' => $scene['output_tokens']])['estimated_total_cost'];
        // Looked up by key, not by dot path: the model ids have dots in them.
        $prices = ((array) config('services.openai.image_prices'))[$image->image_model] ?? null;

        if ($writing === null || ! is_array($prices) || $drawn['input_tokens'] === null || $drawn['output_tokens'] === null) {
            return null;
        }

        return $writing + ($drawn['input_tokens'] * (float) $prices['input'] + $drawn['output_tokens'] * (float) $prices['output']) / 1_000_000;
    }
}

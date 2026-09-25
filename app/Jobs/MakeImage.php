<?php

namespace App\Jobs;

use App\Actions\DrawImage;
use App\Actions\ProposeScene;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\EditorialPolicy;
use App\Models\ImageStyle;
use App\Models\Prompt;
use App\OpenAi\Usage;
use App\Support\ErrorMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * トップ画像 (UI: "Top image", 編成 › 画像): choose a scene from a scheduled
 * original and draw it in the style of its slot's time band.
 */
class MakeImage implements ShouldQueue
{
    use Queueable;

    /** How many of the latest palettes at the same hour the scene writer must differ from. */
    private const RECENT_PALETTES = 3;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public ArticleImage $image) {}

    /** Create the drawing (作成中) pinning prompt, both models, time and band, and queue it. */
    public static function queueFor(Article $article): ArticleImage
    {
        $time = (string) $article->scheduledLocal()?->format('H:i');

        $image = $article->images()->create([
            'prompt_id' => Prompt::forLayer('image')->id,
            'scene_model' => EditorialPolicy::modelFor('image'),
            'image_model' => EditorialPolicy::imageModel(),
            'time' => $time,
            'band' => ImageStyle::bandFor($time),
            'status' => 'making',
        ]);

        self::dispatch($image);

        return $image;
    }

    /** Propose the scene, draw it, store it, and give it to the article; a failure lands on the drawing. */
    /**
     * Queue an image for every scheduled, unpublished original waiting for one (画像作成中); returns how many.
     */
    public static function queueWaiting(): int
    {
        $articles = Article::query()->originals()->whereNotNull('scheduled_at')->whereNull('published_at')->with('image')->get()
            ->filter(fn (Article $article): bool => $article->publicationStatus() === 'imaging' && $article->image?->status !== 'making');
        $articles->each(fn (Article $article) => self::queueFor($article));

        return $articles->count();
    }

    /**
     * The palettes of the latest pictures drawn at the same hour, newest first.
     *
     * @return list<string>
     */
    private static function recentPalettes(ArticleImage $image): array
    {
        return array_values(ArticleImage::query()->where('band', $image->band)->where('status', 'made')->whereNotNull('palette')->whereKeyNot($image->id)
            ->latest('id')->limit(self::RECENT_PALETTES)->pluck('palette')->map(fn ($palette): string => (string) $palette)->all());
    }

    public function handle(ProposeScene $propose, DrawImage $draw): void
    {
        $image = $this->image;
        $article = $image->article;

        try {
            // Only a scheduled original.
            if ($article->translated_from_id !== null || $article->body === null || $article->scheduled_at === null) {
                throw new RuntimeException(__('Only a scheduled original article gets a top image.'));
            }

            $policy = Prompt::textOf($image->prompt, 'image');

            $style = ImageStyle::bands()[$image->band]['style'] ?? '';
            $scene = $propose($policy, (string) $image->scene_model, $article, (array) $article->material?->parts, $style, self::recentPalettes($image));
            $sceneText = trim((string) ($scene['json']['scene'] ?? ''));

            // No scene, no drawing.
            if ($sceneText === '') {
                throw new RuntimeException(__('The agent did not return a scene.'));
            }

            $palette = trim((string) ($scene['json']['palette'] ?? ''));
            $prompt = DrawImage::prompt($sceneText, $style, $palette);
            $drawn = $draw((string) $image->image_model, $prompt);
            $path = "images/{$article->id}/{$image->id}.jpg";
            Storage::disk('local')->put($path, $drawn['bytes']);

            $image->update([
                'status' => 'made',
                'status_message' => __('Drawn by :model.', ['model' => $image->image_model]),
                'scene' => $sceneText,
                'palette' => $palette !== '' ? $palette : null,
                'image_prompt' => $prompt,
                'path' => $path,
                'input_tokens' => $scene['usage']['input_tokens'],
                'output_tokens' => $scene['usage']['output_tokens'],
                'image_input_tokens' => $drawn['usage']['input_tokens'],
                'image_output_tokens' => $drawn['usage']['output_tokens'],
                'latency_ms' => $scene['usage']['latency_ms'] + $drawn['usage']['latency_ms'],
                'estimated_total_cost' => self::cost($image, $scene['usage'], $drawn['usage']),
            ]);

            // The image and the time of day it was made for.
            $article->update(['image_path' => $path, 'image_time' => $image->time]);
        } catch (Throwable $exception) {
            $image->update(['status' => 'failed', 'status_message' => ErrorMessage::of($exception)]);
        }
    }

    /**
     * The cost of both calls in USD; null when either price is unknown.
     *
     * @param  array{input_tokens: ?int, cached_tokens: ?int, cache_write_tokens: ?int, output_tokens: ?int, latency_ms: int}  $scene
     * @param  array{input_tokens: ?int, output_tokens: ?int, latency_ms: int}  $drawn
     */
    private static function cost(ArticleImage $image, array $scene, array $drawn): ?float
    {
        $writing = Usage::estimatedCost((string) $image->scene_model, $scene)['estimated_total_cost'];
        // By key, not dot path: model ids contain dots.
        $prices = ((array) config('services.openai.image_prices'))[$image->image_model] ?? null;

        // Any price or token count unknown: no cost.
        if ($writing === null || ! is_array($prices) || $drawn['input_tokens'] === null || $drawn['output_tokens'] === null) {
            return null;
        }

        return $writing + ($drawn['input_tokens'] * (float) $prices['input'] + $drawn['output_tokens'] * (float) $prices['output']) / 1_000_000;
    }
}

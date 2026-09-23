<?php

namespace App\Actions;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * The second half of a top image (トップ画像): the scene, the style of the
 * hour and what may never be drawn go to the image model as one prompt
 * (the Images API), and the image comes back as JPEG bytes, wide enough
 * to head an article. It only draws; App\Jobs\MakeImage keeps the image.
 */
class DrawImage
{
    private const ENDPOINT = 'https://api.openai.com/v1/images/generations';

    /** 16:9, both sides multiples of 16 as the API asks. */
    public const SIZE = '1536x864';

    /** What is never drawn, whatever the scene or the style: the image serves every language and must not pass for a photograph of the event. Shown on the screen. */
    public const NEVER = 'No text, letters or numbers anywhere in the image. No logos, brand names or real people. Not a photograph. A wide composition to head an article.';

    /** The prompt the image model is given: the scene, then the style of the hour, then what is never drawn. */
    public static function prompt(string $scene, string $style): string
    {
        return trim($scene)."\n\nStyle: ".trim($style)."\n\n".self::NEVER;
    }

    /**
     * @return array{bytes: string, usage: array{input_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $model, string $prompt): array
    {
        $key = (string) config('services.openai.key');

        if ($key === '') {
            throw new RuntimeException(__('OPENAI_API_KEY is not set.'));
        }

        $started = hrtime(true);

        $response = Http::withToken($key)
            ->timeout(300)
            ->post(self::ENDPOINT, ['model' => $model, 'prompt' => $prompt, 'size' => self::SIZE, 'quality' => 'medium', 'output_format' => 'jpeg', 'n' => 1])
            ->throw();

        $bytes = base64_decode((string) $response->json('data.0.b64_json'), true);

        if ($bytes === false || $bytes === '') {
            throw new RuntimeException(__('The image model did not return an image.'));
        }

        return [
            'bytes' => $bytes,
            'usage' => [
                'input_tokens' => is_numeric($response->json('usage.input_tokens')) ? (int) $response->json('usage.input_tokens') : null,
                'output_tokens' => is_numeric($response->json('usage.output_tokens')) ? (int) $response->json('usage.output_tokens') : null,
                'latency_ms' => (int) round((hrtime(true) - $started) / 1_000_000),
            ],
        ];
    }
}

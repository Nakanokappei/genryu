<?php

namespace App\Actions;

use App\OpenAi\Client;
use RuntimeException;

/**
 * Draws a トップ画像 (UI "Top image") with the Images API from the scene,
 * the style of the hour and NEVER; returns JPEG bytes. MakeImage stores it.
 */
class DrawImage
{
    /** 16:9, both sides multiples of 16 as the API asks. */
    public const SIZE = '1536x864';

    /** What is never drawn, whatever the scene or style. Shown on the screen. */
    public const NEVER = 'No text, letters or numbers anywhere in the image. No logos, brand names or real people. Not a photograph. A wide composition to head an article.';

    /** The image prompt: scene, style, then NEVER. */
    public static function prompt(string $scene, string $style): string
    {
        return trim($scene)."\n\nStyle: ".trim($style)."\n\n".self::NEVER;
    }

    /**
     * Draws the image and returns its bytes and usage.
     *
     * @return array{bytes: string, usage: array{input_tokens: ?int, output_tokens: ?int, latency_ms: int}}
     */
    public function __invoke(string $model, string $prompt): array
    {
        $started = hrtime(true);

        $response = Client::post('images/generations', ['model' => $model, 'prompt' => $prompt, 'size' => self::SIZE, 'quality' => 'medium', 'output_format' => 'jpeg', 'n' => 1], 300);

        $bytes = base64_decode((string) $response->json('data.0.b64_json'), true);

        // No image in the answer.
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

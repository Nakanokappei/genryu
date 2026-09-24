<?php

namespace App\Actions;

use App\OpenAi\Client;

/**
 * Embeds texts with the OpenAI Embeddings API for the 意味フィルタ
 * (UI "Semantic filter"); vectors are unit length, so similarity is the dot product.
 */
class Embed
{
    /** Texts per request. */
    private const BATCH = 100;

    /**
     * Embeds the texts in batches and returns the unit vectors and total tokens.
     *
     * @param  list<string>  $texts
     * @return array{vectors: list<list<float>>, tokens: int}
     */
    public function __invoke(array $texts, string $model): array
    {
        $vectors = [];
        $tokens = 0;

        foreach (array_chunk($texts, self::BATCH) as $batch) {
            $body = Client::post('embeddings', ['model' => $model, 'input' => $batch], 120)->json();

            foreach ($body['data'] as $row) {
                $vectors[] = self::unit($row['embedding']);
            }

            $tokens += (int) ($body['usage']['total_tokens'] ?? 0);
        }

        return ['vectors' => $vectors, 'tokens' => $tokens];
    }

    /**
     * Scales a vector to unit length.
     *
     * @param  list<float|int>  $vector
     * @return list<float>
     */
    private static function unit(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn ($x): float => $x * $x, $vector))) ?: 1.0;

        return array_map(fn ($x): float => $x / $length, $vector);
    }
}

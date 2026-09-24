<?php

namespace App\Actions;

use App\OpenAi\Client;

/**
 * Embed texts with the OpenAI Embeddings API, for the 意味フィルタ. The
 * vectors come back at unit length, so a similarity between two of them
 * is their dot product. Texts are sent a hundred at a time.
 */
class Embed
{
    private const BATCH = 100;

    /**
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
     * @param  list<float|int>  $vector
     * @return list<float>
     */
    private static function unit(array $vector): array
    {
        $length = sqrt(array_sum(array_map(fn ($x): float => $x * $x, $vector))) ?: 1.0;

        return array_map(fn ($x): float => $x / $length, $vector);
    }
}

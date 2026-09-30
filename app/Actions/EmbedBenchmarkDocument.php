<?php

namespace App\Actions;

use App\Models\Document;
use App\Models\DocumentEmbedding;
use App\Models\EditorialPolicy;

/**
 * A ベンチマーク's document as its embedding only: the body read on
 * fetching is embedded with the semantic filter's model and not kept.
 */
class EmbedBenchmarkDocument
{
    public function __construct(private Embed $embed) {}

    /** Embeds the title and body and keeps the vector; returns the characters embedded. */
    public function __invoke(Document $document, string $markdown): int
    {
        $model = EditorialPolicy::semanticFilter()['model'];
        $text = MeasureLikeness::textOf($document->title, $markdown);
        ['vectors' => [$vector], 'tokens' => $tokens] = ($this->embed)([$text], $model);
        DocumentEmbedding::query()->updateOrCreate(['document_id' => $document->id], ['model' => $model, 'vector' => $vector, 'tokens' => $tokens]);

        return mb_strlen($text);
    }
}

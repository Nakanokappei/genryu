<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A document's embedding for the 意味フィルタ (UI "Semantic filter"), one
 * per document for the model that made it.
 *
 * @property list<float> $vector unit length, so a similarity is a dot product
 */
class DocumentEmbedding extends Model
{
    protected $fillable = ['document_id', 'model', 'vector', 'tokens'];

    protected function casts(): array
    {
        return ['vector' => 'array'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

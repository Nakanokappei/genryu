<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The embedding of a document for the 意味フィルタ (UI: "Semantic
 * filter"): its title and text as the embedding model read them, kept so
 * the likeness can be measured again, against changed definitions or
 * examples, without calling the model. One per document, for the model
 * it was made with; a changed model makes it again.
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

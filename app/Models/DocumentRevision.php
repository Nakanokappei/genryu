<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 版 (UI: "Revision"): the Markdown a document had at one time, kept when
 * it is written (fetched, rebuilt from the original, read with revised
 * settings) and never changed after. A screening or a material pins the
 * revision it was made from, so the text under a decision or a
 * material's quotes is exactly the one they were made on, whatever the
 * document holds now. Recorded by Document on save.
 */
class DocumentRevision extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['document_id', 'markdown', 'sha256', 'chars'];

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

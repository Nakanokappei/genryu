<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 版 (UI "Revision"): a document's Markdown at one time, never changed;
 * screenings and materials pin the one they were made from.
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

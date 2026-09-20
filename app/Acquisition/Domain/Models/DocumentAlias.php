<?php

namespace App\Acquisition\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Another URL that resolved to an existing document, with the reason it
 * was judged the same (canonical link, feed GUID, ...).
 *
 * @property int $id
 * @property int $document_id
 * @property string $url
 * @property string $normalized_url
 * @property string $reason
 * @property Carbon|null $created_at
 */
#[Fillable(['document_id', 'url', 'normalized_url', 'reason'])]
class DocumentAlias extends Model
{
    const UPDATED_AT = null;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}

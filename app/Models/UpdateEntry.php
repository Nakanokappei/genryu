<?php

namespace App\Models;

use Database\Factories\UpdateEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 更新リスト (UI: "Updates"): one item on a source's update list, before
 * the document behind it is fetched.
 */
class UpdateEntry extends Model
{
    /** @use HasFactory<UpdateEntryFactory> */
    use HasFactory;

    protected $fillable = ['source_id', 'title', 'url', 'published_at'];

    protected function casts(): array
    {
        return ['published_at' => 'date'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasMany<Document, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}

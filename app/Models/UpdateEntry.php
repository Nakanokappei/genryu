<?php

namespace App\Models;

use Database\Factories\UpdateEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 更新リスト (UI: "Updates"): one item on a source's update list; the
 * document behind it is fetched in the background, unless the title has
 * an exclude keyword of the editorial policy (UI: 対象外, excluded_by).
 */
class UpdateEntry extends Model
{
    /** @use HasFactory<UpdateEntryFactory> */
    use HasFactory;

    protected $fillable = ['source_id', 'title', 'url', 'published_at', 'excluded_by'];

    protected function casts(): array
    {
        return ['published_at' => 'date'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasOne<Document, $this> */
    public function document(): HasOne
    {
        return $this->hasOne(Document::class);
    }
}

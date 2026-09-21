<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 文書 (UI: "Documents"): the HTML or PDF fetched for an update, kept as
 * the original file and as Markdown. Fetched in the background by
 * App\Jobs\FetchDocument; status fetching / fetched / failed (UI: 取得中 /
 * 取得済み / 失敗).
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const FORMATS = ['html', 'pdf'];

    protected $fillable = ['update_entry_id', 'title', 'url', 'format', 'original_path', 'markdown', 'fetched_at', 'status', 'status_message'];

    protected function casts(): array
    {
        return ['fetched_at' => 'datetime'];
    }

    /** @return BelongsTo<UpdateEntry, $this> */
    public function updateEntry(): BelongsTo
    {
        return $this->belongsTo(UpdateEntry::class);
    }

    /** @return HasMany<Material, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(Material::class);
    }
}

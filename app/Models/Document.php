<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 文書 (UI: "Documents"): a document found on a source's update list
 * (stage 2.1 of docs/HANDOVER.md) and fetched from the source (stage 2.2):
 * the HTML or PDF kept as the original file and read into Markdown, by
 * App\Jobs\FetchDocument in the background. Status null until a fetch is
 * queued, then fetching / fetched / failed (UI: 取得中 / 取得済み / 失敗).
 * A document whose title has an exclude keyword of the editorial policy is
 * listed as 対象外 (excluded_by) and not fetched.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const FORMATS = ['html', 'pdf'];

    protected $fillable = ['source_id', 'title', 'url', 'published_at', 'excluded_by', 'format', 'original_path', 'markdown', 'fetched_at', 'status', 'status_message'];

    protected function casts(): array
    {
        return ['published_at' => 'date', 'fetched_at' => 'datetime'];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** @return HasOne<Material, $this> */
    public function material(): HasOne
    {
        return $this->hasOne(Material::class);
    }
}

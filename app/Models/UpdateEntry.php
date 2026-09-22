<?php

namespace App\Models;

use Database\Factories\UpdateEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 更新リスト (UI: "Updates"): one item on a source's update list, and the
 * document fetched for it (stage 2.2 of docs/HANDOVER.md): the HTML or
 * PDF kept as the original file and as Markdown, by App\Jobs\FetchDocument
 * in the background. Status null until a fetch is queued, then fetching /
 * fetched / failed (UI: 取得中 / 取得済み / 失敗). An entry whose title has
 * an exclude keyword of the editorial policy is listed as 対象外
 * (excluded_by) and nothing is fetched for it.
 */
class UpdateEntry extends Model
{
    /** @use HasFactory<UpdateEntryFactory> */
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

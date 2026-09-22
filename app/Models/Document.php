<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 文書 (UI: "Documents"): a document found on a source's update list
 * (stage 2.1 of docs/HANDOVER.md) and fetched from the source (stage 2.2):
 * the HTML or PDF kept as the original file and read into Markdown, by
 * App\Jobs\FetchDocument in the background. Status null until a fetch is
 * queued, then fetching / fetched / failed (UI: 取得中 / 取得済み / 失敗).
 * A document whose title has an exclude keyword of the editorial policy is
 * listed as 対象外 (excluded_by) and not fetched. A fetched document is
 * screened by App\Jobs\ScreenDocument (UI: スクリーニング); the latest
 * screening (screening_id) carries the decision 採用 / 不採用 / 要確認.
 */
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    public const FORMATS = ['html', 'pdf'];

    /** A fetched body shorter than this (UI: 本文が短い) is probably a teaser: the source's document settings may miss the body. */
    public const SHORT_BODY_CHARS = 1000;

    protected $fillable = ['source_id', 'title', 'url', 'published_at', 'excluded_by', 'format', 'original_path', 'markdown', 'fetched_at', 'status', 'status_message', 'screening_id'];

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

    /** @return BelongsTo<Screening, $this> the latest screening of the document */
    public function screening(): BelongsTo
    {
        return $this->belongsTo(Screening::class);
    }

    /** @return HasMany<Screening, $this> every screening of the document, latest first */
    public function screenings(): HasMany
    {
        return $this->hasMany(Screening::class)->latest('id');
    }

    /**
     * Whether the fetched body is suspiciously short: the settings of the
     * source caught a teaser, a header or a page whose body sits elsewhere.
     * An excluded document is not worth the warning.
     */
    public function hasShortBody(): bool
    {
        return $this->status === 'fetched' && $this->excluded_by === null && mb_strlen((string) $this->markdown) < self::SHORT_BODY_CHARS;
    }

    /**
     * Whether the gate lets the document on to the detailed analysis: a
     * rejected document does not go; one not screened yet, or to be
     * reviewed, is not stopped here.
     */
    public function isRejected(): bool
    {
        return $this->screening?->decision === 'reject';
    }
}

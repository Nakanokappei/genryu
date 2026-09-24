<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 抜き取り点検 (UI: "Spot check"): a document drawn for a person to judge
 * whether it is like this media, set against what the semantic filter
 * made of it when it was drawn (App\Actions\DrawSpotCheck).
 *
 * @property CarbonImmutable $drawn_on
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $confirmed_at when a person closed the day's spot check (確定), on every row of the day
 */
class SpotCheck extends Model
{
    /**
     * The strata of a day's draw and how many each gives (UI 通過 / 閾値のすぐ下
     * / それより下): drawn at random the draw would be almost all below the
     * threshold (94% of arXiv on 2026-09-24), which says little about the
     * line itself.
     */
    public const STRATA = ['let_through' => 3, 'just_below' => 4, 'far_below' => 3];

    /** How far below the threshold "just below" reaches. */
    public const JUST_BELOW_WIDTH = 0.10;

    /** A person's verdict: like this media, unlike it, or cannot tell. */
    public const VERDICTS = ['like', 'unlike', 'cannot_tell'];

    protected $fillable = ['document_id', 'drawn_on', 'stratum', 'weight', 'likeness', 'threshold', 'let_through', 'title_ja', 'summary_ja', 'translation_error', 'verdict', 'decided_by', 'decided_at', 'confirmed_at', 'confirmed_by'];

    protected function casts(): array
    {
        return ['drawn_on' => 'immutable_date', 'weight' => 'float', 'likeness' => 'float', 'threshold' => 'float', 'let_through' => 'boolean', 'decided_at' => 'datetime', 'confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return BelongsTo<User, $this> who closed the day's spot check */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}

<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 抜き取り点検 (UI "Spot check"): a document drawn for a person to judge,
 * with the semantic filter's likeness, threshold and outcome at drawing.
 *
 * @property CarbonImmutable $drawn_on
 * @property CarbonImmutable|null $decided_at
 * @property CarbonImmutable|null $confirmed_at 確定: when the day was closed, on every row of the day
 */
class SpotCheck extends Model
{
    /** The strata and how many each draws (UI 通過 / 閾値のすぐ下 / それより下). */
    public const STRATA = ['let_through' => 3, 'just_below' => 4, 'far_below' => 3];

    /** How far below the threshold "just below" reaches. */
    public const JUST_BELOW_WIDTH = 0.10;

    /** A person's verdict. */
    public const VERDICTS = ['like' => 'Like this media', 'cannot_tell' => 'Cannot tell', 'unlike' => 'Unlike this media'];

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

    /** @return BelongsTo<User, $this> who closed the day */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }
}

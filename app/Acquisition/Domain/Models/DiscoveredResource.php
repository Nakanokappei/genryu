<?php

namespace App\Acquisition\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A URL encountered during discovery or monitoring, unique per source by
 * its normalized form (ADR-0003).
 *
 * @property int $id
 * @property int $source_id
 * @property int $first_seen_run_id
 * @property int $last_seen_run_id
 * @property string $url
 * @property string $normalized_url
 * @property string $relation
 * @property string|null $media_type
 * @property int|null $depth
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 */
#[Fillable([
    'source_id', 'first_seen_run_id', 'last_seen_run_id', 'url', 'normalized_url',
    'relation', 'media_type', 'depth', 'first_seen_at', 'last_seen_at',
])]
class DiscoveredResource extends Model
{
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}

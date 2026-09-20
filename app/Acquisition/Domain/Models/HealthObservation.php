<?php

namespace App\Acquisition\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One health metric sample for a source (plan §13), stored next to the
 * baseline it was judged against so the judgement can be audited later.
 *
 * @property int $id
 * @property int $source_id
 * @property int|null $run_id
 * @property string $metric
 * @property string $value
 * @property string|null $baseline
 * @property string|null $status
 * @property Carbon $observed_at
 */
#[Fillable(['source_id', 'run_id', 'metric', 'value', 'baseline', 'status', 'observed_at'])]
class HealthObservation extends Model
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
            'observed_at' => 'datetime',
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

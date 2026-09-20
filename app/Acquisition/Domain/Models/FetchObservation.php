<?php

namespace App\Acquisition\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The fact of one HTTP fetch. Recorded on every attempt, including 304s and
 * failures, so idempotent re-fetches still leave an operational trace
 * (AT-05) without creating revisions.
 *
 * @property int $id
 * @property int $run_id
 * @property int $source_id
 * @property string $url
 * @property string|null $final_url
 * @property int|null $status
 * @property array<string, string>|null $headers
 * @property Carbon $retrieved_at
 * @property int|null $duration_ms
 * @property int $attempts
 * @property int|null $raw_artifact_id
 * @property string|null $error_code
 * @property string|null $error_message
 */
#[Fillable([
    'run_id', 'source_id', 'url', 'final_url', 'status', 'headers', 'retrieved_at',
    'duration_ms', 'attempts', 'raw_artifact_id', 'error_code', 'error_message',
])]
class FetchObservation extends Model
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
            'headers' => 'array',
            'retrieved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AcquisitionRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRun::class, 'run_id');
    }

    /**
     * @return BelongsTo<RawArtifact, $this>
     */
    public function rawArtifact(): BelongsTo
    {
        return $this->belongsTo(RawArtifact::class);
    }
}

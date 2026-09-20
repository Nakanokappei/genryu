<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Enums\RunMode;
use App\Acquisition\Domain\Enums\RunStatus;
use Database\Factories\Acquisition\AcquisitionRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One Discovery, Monitoring or Reprocess execution. Everything an operator
 * needs to trace ("which profile, which model, how many failures") hangs
 * off this row.
 *
 * @property int $id
 * @property RunMode $mode
 * @property int $source_id
 * @property int|null $profile_id
 * @property RunStatus $status
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property array<string, mixed>|null $budget
 * @property array<string, int> $counters
 * @property array<string, mixed>|null $agent_metadata
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'mode', 'source_id', 'profile_id', 'status', 'started_at', 'finished_at',
    'budget', 'counters', 'agent_metadata', 'error_message',
])]
#[UseFactory(AcquisitionRunFactory::class)]
class AcquisitionRun extends Model
{
    /** @use HasFactory<AcquisitionRunFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => RunMode::class,
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'budget' => 'array',
            'counters' => 'array',
            'agent_metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Source, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * The profile version this run executed with (null for Discovery).
     *
     * @return BelongsTo<SourceProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(SourceProfile::class, 'profile_id');
    }

    /**
     * @return HasMany<ToolInvocation, $this>
     */
    public function toolInvocations(): HasMany
    {
        return $this->hasMany(ToolInvocation::class, 'run_id');
    }

    /**
     * @return HasMany<FetchObservation, $this>
     */
    public function fetchObservations(): HasMany
    {
        return $this->hasMany(FetchObservation::class, 'run_id');
    }
}

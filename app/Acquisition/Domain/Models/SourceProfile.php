<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Enums\ProfileStatus;
use Database\Factories\Acquisition\SourceProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version of a source's monitoring specification (ADR-0005).
 * Versions are added, never edited in place; the database guarantees at
 * most one ACTIVE version per source.
 *
 * @property int $id
 * @property int $source_id
 * @property int $version
 * @property int $schema_version
 * @property ProfileStatus $status
 * @property array<string, mixed> $profile_json
 * @property int|null $created_by_run_id
 * @property string|null $change_reason
 * @property Carbon|null $approved_at
 * @property string|null $approved_by
 * @property Carbon|null $superseded_at
 * @property Carbon|null $rejected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'source_id', 'version', 'schema_version', 'status', 'profile_json', 'created_by_run_id',
    'change_reason', 'approved_at', 'approved_by', 'superseded_at', 'rejected_at',
])]
#[UseFactory(SourceProfileFactory::class)]
class SourceProfile extends Model
{
    /** @use HasFactory<SourceProfileFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ProfileStatus::class,
            'profile_json' => 'array',
            'approved_at' => 'datetime',
            'superseded_at' => 'datetime',
            'rejected_at' => 'datetime',
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
     * The Discovery run whose candidate this profile is, if Agent-made.
     *
     * @return BelongsTo<AcquisitionRun, $this>
     */
    public function createdByRun(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRun::class, 'created_by_run_id');
    }
}

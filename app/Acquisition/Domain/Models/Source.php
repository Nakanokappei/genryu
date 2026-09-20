<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Enums\HealthStatus;
use App\Acquisition\Domain\Enums\SourceStatus;
use Database\Factories\Acquisition\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * An organisation whose primary sources we monitor (DARPA, NEDO, ...).
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $base_url
 * @property SourceStatus $status
 * @property HealthStatus $health_status
 * @property int $consecutive_failures
 * @property Carbon|null $next_run_not_before
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['key', 'name', 'base_url', 'status', 'health_status', 'consecutive_failures', 'next_run_not_before'])]
#[UseFactory(SourceFactory::class)]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SourceStatus::class,
            'health_status' => HealthStatus::class,
            'next_run_not_before' => 'datetime',
        ];
    }

    /**
     * Every profile version ever created for this source.
     *
     * @return HasMany<SourceProfile, $this>
     */
    public function profiles(): HasMany
    {
        return $this->hasMany(SourceProfile::class);
    }

    /**
     * The single profile Monitoring is allowed to use, if any.
     *
     * @return HasOne<SourceProfile, $this>
     */
    public function activeProfile(): HasOne
    {
        return $this->hasOne(SourceProfile::class)->where('status', 'ACTIVE');
    }

    /**
     * @return HasMany<AcquisitionRun, $this>
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AcquisitionRun::class);
    }

    /**
     * @return HasMany<Document, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /**
     * @return HasMany<SourceEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SourceEvent::class);
    }
}

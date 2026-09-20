<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Enums\ToolOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Audit record of one Tool call. Only a digest of the request is stored;
 * bodies never enter the audit log (plan §7).
 *
 * @property int $id
 * @property int $run_id
 * @property string $tool
 * @property string $request_digest
 * @property ToolOutcome $outcome
 * @property string|null $error_code
 * @property int $duration_ms
 * @property string|null $correlation_id
 * @property Carbon|null $created_at
 */
#[Fillable(['run_id', 'tool', 'request_digest', 'outcome', 'error_code', 'duration_ms', 'correlation_id'])]
class ToolInvocation extends Model
{
    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'outcome' => ToolOutcome::class,
        ];
    }

    /**
     * @return BelongsTo<AcquisitionRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AcquisitionRun::class, 'run_id');
    }
}

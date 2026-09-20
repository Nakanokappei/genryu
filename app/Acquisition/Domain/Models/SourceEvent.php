<?php

namespace App\Acquisition\Domain\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Something an operator should know about a source: PARSER_DRIFT, profile
 * activation or rollback, identity conflicts, outages. Events stay open
 * until resolved_at is set; that is the only field that ever changes.
 *
 * @property int $id
 * @property int $source_id
 * @property int|null $run_id
 * @property string $event_type
 * @property string $severity
 * @property array<string, mixed>|null $evidence
 * @property Carbon|null $resolved_at
 * @property Carbon|null $created_at
 */
#[Fillable(['source_id', 'run_id', 'event_type', 'severity', 'evidence', 'resolved_at'])]
class SourceEvent extends Model
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
            'evidence' => 'array',
            'resolved_at' => 'datetime',
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

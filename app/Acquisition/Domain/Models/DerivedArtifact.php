<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Boundary for future derivations (evidence, summaries, ...). Phase 0
 * defines the table and provenance link but writes nothing here (plan §9.3).
 *
 * @property int $id
 * @property int $normalized_artifact_id
 * @property string $derivation_type
 * @property string $derivation_version
 * @property string $blob_uri
 * @property string $sha256
 * @property Carbon|null $created_at
 */
#[Fillable(['normalized_artifact_id', 'derivation_type', 'derivation_version', 'blob_uri', 'sha256'])]
class DerivedArtifact extends Model
{
    use AppendOnly;

    const UPDATED_AT = null;

    /**
     * @return BelongsTo<NormalizedArtifact, $this>
     */
    public function normalizedArtifact(): BelongsTo
    {
        return $this->belongsTo(NormalizedArtifact::class);
    }
}

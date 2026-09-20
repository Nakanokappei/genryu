<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Models\Concerns\AppendOnly;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Output of one parser version for one revision. Reprocessing adds rows
 * with a new parser_id; it never replaces the old ones (plan §11, AT-07).
 *
 * @property int $id
 * @property int $revision_id
 * @property string $parser_id
 * @property string $normalizer_version
 * @property string $blob_uri
 * @property string $sha256
 * @property array<string, mixed>|null $quality
 * @property array<int, mixed>|null $warnings
 * @property int|null $produced_in_run_id
 * @property Carbon|null $created_at
 */
#[Fillable(['revision_id', 'parser_id', 'normalizer_version', 'blob_uri', 'sha256', 'quality', 'warnings', 'produced_in_run_id'])]
class NormalizedArtifact extends Model
{
    use AppendOnly;

    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'quality' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<DocumentRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'revision_id');
    }
}

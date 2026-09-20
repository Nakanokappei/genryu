<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Models\Concerns\AppendOnly;
use Database\Factories\Acquisition\DocumentRevisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One content version of a document. content_hash is the SHA-256 of the RAW
 * bytes (ADR-0003); the database rejects a second revision with the same
 * hash, which is what makes revision appends idempotent.
 *
 * @property int $id
 * @property int $document_id
 * @property int $revision_no
 * @property int $raw_artifact_id
 * @property string $content_hash
 * @property Carbon $detected_at
 * @property int|null $detected_in_run_id
 * @property Carbon|null $created_at
 */
#[Fillable(['document_id', 'revision_no', 'raw_artifact_id', 'content_hash', 'detected_at', 'detected_in_run_id'])]
#[UseFactory(DocumentRevisionFactory::class)]
class DocumentRevision extends Model
{
    use AppendOnly;

    /** @use HasFactory<DocumentRevisionFactory> */
    use HasFactory;

    const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'detected_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<RawArtifact, $this>
     */
    public function rawArtifact(): BelongsTo
    {
        return $this->belongsTo(RawArtifact::class);
    }

    /**
     * @return HasMany<NormalizedArtifact, $this>
     */
    public function normalizedArtifacts(): HasMany
    {
        return $this->hasMany(NormalizedArtifact::class, 'revision_id');
    }
}

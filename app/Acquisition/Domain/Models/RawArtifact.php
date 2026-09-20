<?php

namespace App\Acquisition\Domain\Models;

use App\Acquisition\Domain\Models\Concerns\AppendOnly;
use Database\Factories\Acquisition\RawArtifactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Metadata of one immutable original response. The bytes live in the
 * BlobStore under a content-addressed key; sha256 here equals the key.
 *
 * @property int $id
 * @property string $blob_uri
 * @property string $sha256
 * @property int $bytes
 * @property string $media_type
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 */
#[Fillable(['blob_uri', 'sha256', 'bytes', 'media_type', 'metadata'])]
#[UseFactory(RawArtifactFactory::class)]
class RawArtifact extends Model
{
    use AppendOnly;

    /** @use HasFactory<RawArtifactFactory> */
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
            'metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<DocumentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class);
    }
}

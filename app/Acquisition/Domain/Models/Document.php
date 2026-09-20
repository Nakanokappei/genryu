<?php

namespace App\Acquisition\Domain\Models;

use Database\Factories\Acquisition\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A logical document within a source, identified by stable_key (ADR-0003),
 * not by URL. identity_rule records which derivation rule produced the key.
 *
 * @property int $id
 * @property int $source_id
 * @property string $stable_key
 * @property string $identity_rule
 * @property string|null $canonical_url
 * @property string|null $document_type
 * @property Carbon $first_seen_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['source_id', 'stable_key', 'identity_rule', 'canonical_url', 'document_type', 'first_seen_at', 'last_seen_at'])]
#[UseFactory(DocumentFactory::class)]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
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
     * @return HasMany<DocumentRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(DocumentRevision::class);
    }

    /**
     * @return HasMany<DocumentAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(DocumentAlias::class);
    }
}

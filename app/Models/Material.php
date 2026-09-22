<?php

namespace App\Models;

use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 素材情報 (UI: "Materials"): what a document yields for the articles,
 * built by App\Jobs\ExtractMaterial per the structuring layer of the
 * editorial policy and stored as JSON (data): the evidence quoted from
 * the document with its lines (provenance), the claims resting on it,
 * the inferences resting on those, the technology transition, the
 * engineering, the tensions and the possible angles. Pinned to the
 * document revision it was made from, the prompt version and the model;
 * status extracting / extracted / failed (UI: 抽出中 / 抽出済み / 失敗),
 * with the report of the checks (validation) and the usage of the calls.
 *
 * @property array<string, mixed>|null $data the material JSON (App\Actions\ProposeMaterial::SCHEMA_VERSION)
 * @property list<string>|null $validation the problems the last checks found, empty when they passed
 */
class Material extends Model
{
    /** @use HasFactory<MaterialFactory> */
    use HasFactory;

    protected $fillable = [
        'document_id', 'document_revision_id', 'prompt_id', 'model', 'data', 'status', 'status_message', 'validation',
        'input_tokens', 'cached_tokens', 'cache_write_tokens', 'output_tokens', 'latency_ms', 'estimated_total_cost',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'validation' => 'array', 'estimated_total_cost' => 'float'];
    }

    /**
     * The claims of the material by id, for the screens and the article.
     *
     * @return array<string, array<string, mixed>>
     */
    public function claims(): array
    {
        $claims = [];

        foreach ($this->data['claims'] ?? [] as $claim) {
            $claims[(string) $claim['id']] = $claim;
        }

        return $claims;
    }

    /**
     * The spans of the material by id: the quotes and their lines.
     *
     * @return array<string, array<string, mixed>>
     */
    public function spans(): array
    {
        $spans = [];

        foreach ($this->data['provenance']['primary_spans'] ?? [] as $span) {
            $spans[(string) $span['id']] = $span;
        }

        return $spans;
    }

    /**
     * The angle the material recommends, if any.
     *
     * @return array<string, mixed>|null
     */
    public function recommendedAngle(): ?array
    {
        $id = $this->data['editorial']['recommended_angle_id'] ?? null;

        foreach ($this->data['editorial']['possible_angles'] ?? [] as $angle) {
            if ($id !== null && ($angle['id'] ?? null) === $id) {
                return $angle;
            }
        }

        return null;
    }

    /** @return BelongsTo<DocumentRevision, $this> the Markdown the material was made from */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(DocumentRevision::class, 'document_revision_id');
    }

    /** @return BelongsTo<Prompt, $this> */
    public function prompt(): BelongsTo
    {
        return $this->belongsTo(Prompt::class);
    }

    /** @return BelongsTo<Document, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /** @return HasMany<Article, $this> */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}

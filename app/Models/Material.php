<?php

namespace App\Models;

use App\Actions\ProposeMaterial;
use Database\Factories\MaterialFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 素材情報 (UI: "Materials"): what a document yields for the articles,
 * built by App\Jobs\ExtractMaterial per the structuring layer of the
 * editorial policy and stored as JSON (data): the change the primary
 * source shows, seen through the editorial lenses that hold (each with
 * its before / change / after / tension / angle and the claims behind
 * them), the transition of the technology's state, the angles
 * recommended for an article, what is missing and what to watch next.
 * Every claim says where it comes from — the primary source, general
 * knowledge, or inference on both. Pinned to the
 * document revision it was made from, the prompt version and the model;
 * status extracting / extracted / failed (UI: 抽出中 / 抽出済み / 失敗),
 * with the report of the checks (validation) and the usage of the calls.
 *
 * @property array<string, mixed>|null $data the dossier: only what the model could support
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
     * The lenses that hold, in the order the policy presents them
     * (jsonb stores keys sorted by length and letter), any other key
     * after them.
     *
     * @return array<string, array<string, mixed>>
     */
    public function lenses(): array
    {
        $lenses = (array) ($this->data['editorial_lenses'] ?? []);
        $ordered = [];

        foreach (ProposeMaterial::LENSES as $lens) {
            if (array_key_exists($lens, $lenses)) {
                $ordered[$lens] = (array) $lenses[$lens];
            }
        }

        return [...$ordered, ...$lenses];
    }

    /**
     * How many statements come from the primary source, how many from
     * the model's general knowledge and how many from inference on both:
     * what this PoC is out to measure.
     *
     * @return array<string, int>
     */
    public function claimTypes(): array
    {
        $counts = array_fill_keys(ProposeMaterial::CLAIM_TYPES, 0);

        foreach ($this->claims() as $claim) {
            $type = (string) ($claim['type'] ?? '');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * Every claim of the dossier, wherever it stands: under a lens,
     * behind the transition, or behind a recommended angle.
     *
     * @return list<array<string, mixed>>
     */
    public function claims(): array
    {
        $lists = [
            ...array_map(fn (array $lens): array => (array) ($lens['claims'] ?? []), array_values($this->lenses())),
            (array) ($this->data['technology_transition']['evidence'] ?? []),
            ...array_map(fn (mixed $angle): array => (array) (((array) $angle)['primary_evidence'] ?? []), (array) ($this->data['recommended_angles'] ?? [])),
        ];

        return array_values(array_filter(array_merge(...$lists), is_array(...)));
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
